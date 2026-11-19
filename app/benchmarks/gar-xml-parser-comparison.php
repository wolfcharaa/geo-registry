<?php

declare(strict_types=1);

use App\Benchmark\Support\GarXmlTransformAccumulator;
use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlCatalog;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlRowReader;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlSourceFile;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlStructure;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlStructureInspector;
use BenMorel\XMLStreamer\XMLStreamer;
use Prewk\XmlStringStreamer;
use Prewk\XmlStringStreamer\Parser\UniqueNode;
use Prewk\XmlStringStreamer\Stream\File;
use Sabre\Xml\Reader as SabreReader;

require \dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Support/GarXmlTransformAccumulator.php';

const XML_PARSER_MODES = [
    'fread-scan',
    'xmlreader-scan',
    'xmlreader-production',
    'xmlreader-direct',
    'xmlreader-fused',
    'sax-scan',
    'sax-transform',
    'dom-transform',
    'simplexml-transform',
    'sabre-transform',
    'benmorel-transform',
    'prewk-scan',
];

/** @return never */
function fail(string $message): never
{
    \fwrite(STDERR, $message . "\n");
    \fwrite(
        STDERR,
        "Usage: php benchmarks/gar-xml-parser-comparison.php <mode> <xml-file> [read-mib=1] [prewk-kib=16]\n",
    );
    \fwrite(STDERR, 'Modes: ' . \implode(', ', XML_PARSER_MODES) . "\n");
    exit(2);
}

/**
 * @template T
 * @param Closure(): T $operation
 * @return array{value: T, seconds: float}
 */
function measured(Closure $operation): array
{
    $startedAt = \hrtime(true);
    $value = $operation();

    return [
        'value' => $value,
        'seconds' => (\hrtime(true) - $startedAt) / 1_000_000_000,
    ];
}

/** @param class-string<\XMLReader> $class */
function openReader(string $class, string $path): \XMLReader
{
    $reader = $class::open($path, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    if (!$reader instanceof \XMLReader) {
        throw new GarImportFailed("{$class} is not an XMLReader implementation.");
    }

    return $reader;
}

/**
 * @param class-string<\XMLReader> $class
 * @return array{rows: int}
 */
function scanReader(string $class, string $path): array
{
    $reader = openReader($class, $path);
    $rows = 0;

    try {
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->depth === 1) {
                ++$rows;
            }
        }
    } finally {
        $reader->close();
    }

    return ['rows' => $rows];
}

/**
 * @param class-string<\XMLReader> $class
 * @return array{rows: int, encoded_bytes: int, sha256: string}
 */
function transformReader(string $class, GarXmlSourceFile $file): array
{
    $reader = openReader($class, $file->path);
    $accumulator = new GarXmlTransformAccumulator($file);

    try {
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->depth === 1) {
                $accumulator->acceptReader($reader);
            }
        }
    } finally {
        $reader->close();
    }

    return $accumulator->result();
}

/** @return array{rows: int, encoded_bytes: int, sha256: string} */
function transformReaderFused(GarXmlSourceFile $file): array
{
    $reader = openReader(\XMLReader::class, $file->path);
    $accumulator = new GarXmlTransformAccumulator($file);

    try {
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->depth === 1) {
                $accumulator->acceptReaderFused($reader);
            }
        }
    } finally {
        $reader->close();
    }

    return $accumulator->result();
}

/** @return array{rows: int, encoded_bytes: int, sha256: string} */
function transformProduction(GarXmlSourceFile $file): array
{
    $accumulator = new GarXmlTransformAccumulator($file);
    foreach (new GarXmlRowReader()->rows($file, 0) as $values) {
        $accumulator->acceptValues($values);
    }

    return $accumulator->result();
}

/**
 * @return array{rows: int}|array{rows: int, encoded_bytes: int, sha256: string}
 * @param positive-int $readBytes
 */
function parseSax(
    GarXmlSourceFile $file,
    GarXmlStructure $structure,
    int $readBytes,
    bool $transform,
): array {
    $parser = \xml_parser_create('UTF-8');
    \xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, false);
    \xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, true);
    \xml_parser_set_option($parser, XML_OPTION_PARSE_HUGE, true);

    $depth = 0;
    $rows = 0;
    $accumulator = $transform ? new GarXmlTransformAccumulator($file) : null;
    \xml_set_element_handler(
        $parser,
        static function (\XMLParser $_parser, string $name, array $attributes) use (
            &$depth,
            &$rows,
            $structure,
            $accumulator,
        ): void {
            if ($depth === 1 && $name === $structure->rowElement) {
                ++$rows;
                $accumulator?->acceptAttributes(saxAttributes($attributes));
            }
            ++$depth;
        },
        static function (\XMLParser $_parser, string $_name) use (&$depth): void {
            --$depth;
        },
    );

    $handle = \fopen($file->path, 'rb');
    if ($handle === false) {
        throw new GarImportFailed("Unable to open GAR XML file: {$file->relativePath}");
    }

    try {
        do {
            $chunk = \fread($handle, $readBytes);
            if ($chunk === false) {
                throw new GarImportFailed("Unable to read GAR XML file: {$file->relativePath}");
            }
            $final = \feof($handle);
            if (\xml_parse($parser, $chunk, $final) !== 1) {
                throw new GarImportFailed(\sprintf(
                    'SAX XML error in %s at line %d: %s',
                    $file->relativePath,
                    \xml_get_current_line_number($parser),
                    \xml_error_string(\xml_get_error_code($parser)),
                ));
            }
        } while (!$final);
    } finally {
        \fclose($handle);
    }

    return $accumulator?->result() ?? ['rows' => $rows];
}

/**
 * @param array<array-key, mixed> $attributes
 * @return array<string, string>
 */
function saxAttributes(array $attributes): array
{
    foreach ($attributes as $name => $value) {
        if (!\is_string($name) || !\is_string($value)) {
            throw new GarImportFailed('SAX returned an invalid XML attribute map.');
        }
    }

    return $attributes;
}

/**
 * @param positive-int $readBytes
 * @return array{bytes: int, sha256: string}
 */
function scanFread(string $path, int $readBytes): array
{
    $handle = \fopen($path, 'rb');
    if ($handle === false) {
        throw new GarImportFailed("Unable to open XML file: {$path}");
    }
    $bytes = 0;
    $hash = \hash_init('sha256');

    try {
        while (!\feof($handle)) {
            $chunk = \fread($handle, $readBytes);
            if ($chunk === false) {
                throw new GarImportFailed("Unable to read XML file: {$path}");
            }
            $bytes += \strlen($chunk);
            \hash_update($hash, $chunk);
        }
    } finally {
        \fclose($handle);
    }

    return ['bytes' => $bytes, 'sha256' => \hash_final($hash)];
}

/** @return array{rows: int, encoded_bytes: int, sha256: string, load_seconds: float, transform_seconds: float} */
function transformDom(GarXmlSourceFile $file): array
{
    if ($file->path === '') {
        throw new GarImportFailed('GAR XML benchmark path must not be empty.');
    }
    $document = new \DOMDocument();
    $load = measured(static fn(): bool => $document->load(
        $file->path,
        LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE,
    ));
    if ($load['value'] !== true || !$document->documentElement instanceof \DOMElement) {
        throw new GarImportFailed("Unable to load GAR XML into DOM: {$file->relativePath}");
    }

    $accumulator = new GarXmlTransformAccumulator($file);
    $transform = measured(static function () use ($document, $accumulator): void {
        foreach ($document->documentElement->childNodes as $node) {
            if ($node instanceof \DOMElement) {
                $accumulator->acceptDomElement($node);
            }
        }
    });

    return $accumulator->result() + [
        'load_seconds' => $load['seconds'],
        'transform_seconds' => $transform['seconds'],
    ];
}

/** @return array{rows: int, encoded_bytes: int, sha256: string, load_seconds: float, transform_seconds: float} */
function transformSimpleXml(GarXmlSourceFile $file): array
{
    $load = measured(static fn(): \SimpleXMLElement|false => \simplexml_load_file(
        $file->path,
        \SimpleXMLElement::class,
        LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE,
    ));
    if (!$load['value'] instanceof \SimpleXMLElement) {
        throw new GarImportFailed("Unable to load GAR XML into SimpleXML: {$file->relativePath}");
    }
    $children = $load['value']->children();
    if (!$children instanceof \SimpleXMLElement) {
        throw new GarImportFailed("GAR XML root has no children: {$file->relativePath}");
    }

    $accumulator = new GarXmlTransformAccumulator($file);
    $transform = measured(static function () use ($children, $accumulator): void {
        foreach ($children as $row) {
            $accumulator->acceptSimpleXml($row);
        }
    });

    return $accumulator->result() + [
        'load_seconds' => $load['seconds'],
        'transform_seconds' => $transform['seconds'],
    ];
}

/** @return array{rows: int, encoded_bytes: int, sha256: string} */
function transformBenMorel(GarXmlSourceFile $file, GarXmlStructure $structure): array
{
    requireExternalParserDependencies();
    $streamer = new XMLStreamer($structure->rootElement, $structure->rowElement);
    $accumulator = new GarXmlTransformAccumulator($file);
    foreach ($streamer->stream($file->path) as $element) {
        $accumulator->acceptDomElement($element);
    }

    return $accumulator->result();
}

/**
 * @param positive-int $readBytes
 * @return array{rows: int, node_bytes: int, sha256: string}
 */
function scanPrewk(GarXmlSourceFile $file, GarXmlStructure $structure, int $readBytes): array
{
    requireExternalParserDependencies();
    $stream = new File($file->path, $readBytes);
    $parser = new UniqueNode([
        'uniqueNode' => $structure->rowElement,
        'checkShortClosing' => true,
    ]);
    $streamer = new XmlStringStreamer($parser, $stream);
    $hash = \hash_init('sha256');
    $rows = 0;
    $nodeBytes = 0;

    while (true) {
        $node = $streamer->getNode();
        if ($node === false) {
            break;
        }
        if (!\is_string($node)) {
            throw new GarImportFailed('Prewk returned a non-string XML node.');
        }
        ++$rows;
        $nodeBytes += \strlen($node);
        \hash_update($hash, $node);
    }

    return [
        'rows' => $rows,
        'node_bytes' => $nodeBytes,
        'sha256' => \hash_final($hash),
    ];
}

function requireExternalParserDependencies(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $autoload = __DIR__ . '/xml-parsers/vendor/autoload.php';
    if (!\is_file($autoload)) {
        throw new GarImportFailed('Run Composer install in benchmarks/xml-parsers before external parser modes.');
    }
    require $autoload;
    $loaded = true;
}

$mode = $argv[1] ?? '';
$path = $argv[2] ?? '';
$readMib = \filter_var($argv[3] ?? '1', FILTER_VALIDATE_INT);
$prewkKib = \filter_var($argv[4] ?? '16', FILTER_VALIDATE_INT);
if (!\in_array($mode, XML_PARSER_MODES, true)) {
    fail('Unknown XML parser benchmark mode.');
}
if ($path === '' || !\is_file($path)) {
    fail('The benchmark needs one existing GAR XML file.');
}
if (!\is_int($readMib) || $readMib < 1) {
    fail('read-mib must be a positive integer.');
}
if (!\is_int($prewkKib) || $prewkKib < 1) {
    fail('prewk-kib must be a positive integer.');
}

$file = new GarXmlCatalog()->manifest($path)->files[0];
$structure = new GarXmlStructureInspector()->inspect($file);
$readBytes = $readMib * 1024 * 1024;
$prewkReadBytes = $prewkKib * 1024;
$operation = match ($mode) {
    'fread-scan' => static fn(): array => scanFread($file->path, $readBytes),
    'xmlreader-scan' => static fn(): array => scanReader(\XMLReader::class, $file->path),
    'xmlreader-production' => static fn(): array => transformProduction($file),
    'xmlreader-direct' => static fn(): array => transformReader(\XMLReader::class, $file),
    'xmlreader-fused' => static fn(): array => transformReaderFused($file),
    'sax-scan' => static fn(): array => parseSax($file, $structure, $readBytes, false),
    'sax-transform' => static fn(): array => parseSax($file, $structure, $readBytes, true),
    'dom-transform' => static fn(): array => transformDom($file),
    'simplexml-transform' => static fn(): array => transformSimpleXml($file),
    'sabre-transform' => static function () use ($file): array {
        requireExternalParserDependencies();

        return transformReader(SabreReader::class, $file);
    },
    'benmorel-transform' => static fn(): array => transformBenMorel($file, $structure),
    'prewk-scan' => static fn(): array => scanPrewk($file, $structure, $prewkReadBytes),
};

$measurement = measured($operation);
$result = [
    'mode' => $mode,
    'runtime' => PHP_VERSION,
    'libxml' => LIBXML_DOTTED_VERSION,
    'file' => $file->path,
    'file_bytes' => $file->size,
    'read_bytes' => $mode === 'prewk-scan' ? $prewkReadBytes : $readBytes,
] + $measurement['value'];
$result['seconds'] = $measurement['seconds'];
$result['peak_memory_bytes'] = \memory_get_peak_usage(true);
\fwrite(STDOUT, \json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
