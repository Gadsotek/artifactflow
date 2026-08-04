<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

use App\Application\PageCatalog\ArtifactPreviewDocumentGuard;
use ReflectionMethod;
use RuntimeException;

$repositoryRoot = dirname(__DIR__, 3);

require $repositoryRoot . '/app/Application/PageCatalog/ArtifactPreviewForeignContentState.php';
require $repositoryRoot . '/app/Application/PageCatalog/ArtifactPreviewDocumentGuard.php';

final class ArtifactParserCorpusRandom
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = $seed === 0 ? 0x6D2B79F5 : $seed;
    }

    public function next(): int
    {
        $value = $this->state;
        $value ^= ($value << 13) & 0xFFFFFFFF;
        $value ^= $value >> 17;
        $value ^= ($value << 5) & 0xFFFFFFFF;
        $this->state = $value & 0xFFFFFFFF;

        return $this->state;
    }

    public function between(int $minimum, int $maximum): int
    {
        if ($minimum > $maximum) {
            throw new RuntimeException('The corpus random range is invalid.');
        }

        return $minimum + ($this->next() % ($maximum - $minimum + 1));
    }

    /**
     * @template TValue
     *
     * @param non-empty-list<TValue> $values
     *
     * @return TValue
     */
    public function pick(array $values): mixed
    {
        return $values[$this->next() % count($values)];
    }
}

$options = getopt('', ['seed:', 'cases:']);

if (!is_array($options)) {
    throw new RuntimeException('Could not read corpus options.');
}

$seedOption = $options['seed'] ?? '1831565813';
$caseCountOption = $options['cases'] ?? '128';

if (
    !is_string($seedOption)
    || preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/', $seedOption) !== 1
    || (int) $seedOption > 0xFFFFFFFF
) {
    throw new RuntimeException('The corpus seed must be an unsigned 32-bit decimal integer.');
}

if (
    !is_string($caseCountOption)
    || preg_match('/\A(?:[1-9]|[1-9][0-9]{1,2})\z/', $caseCountOption) !== 1
    || (int) $caseCountOption > 512
) {
    throw new RuntimeException('The corpus case count must be between 1 and 512.');
}

$seed = (int) $seedOption;
$caseCount = (int) $caseCountOption;
$random = new ArtifactParserCorpusRandom($seed);
$guard = new ArtifactPreviewDocumentGuard();
$rewrite = new ReflectionMethod($guard, 'rewriteDangerousMarkup');

/** @var list<array{index: int, id: string, payload: string, hardened: string}> $cases */
$cases = [];

$appendCase = static function (string $id, string $payload) use (&$cases, $guard, $rewrite): void {
    $hardened = $rewrite->invoke($guard, $payload);

    if (!is_string($hardened)) {
        throw new RuntimeException('The artifact preview rewriter returned an invalid result.');
    }

    $cases[] = [
        'index' => count($cases),
        'id' => $id,
        'payload' => $payload,
        'hardened' => $hardened,
    ];
};

$frame = static function (int $index) use ($random): string {
    $id = sprintf('af-parser-fuzz-%03d', $index);

    return $random->pick([
        '<iframe id="' . $id . '" srcdoc="&lt;p&gt;leaf&lt;/p&gt;"></iframe>',
        '<iframe ID=' . $id . '></iframe>',
        '<IFRAME id="' . $id . '"></IFRAME>',
        '<iframe data-decoy=">" id=' . $id . '></iframe>',
        '<frame id="' . $id . '" srcdoc="&lt;p&gt;leaf&lt;/p&gt;">',
    ]);
};

$mandatoryCases = [
    'control/bare-iframe' => '<!doctype html><iframe id="af-parser-control"></iframe>',
    'regression/annotation-xml-namespace' =>
        '<!doctype html><math><annotation-xml><svg><mtext><style><b>'
        . '<iframe id="af-parser-annotation"></iframe></style>',
    'regression/script-double-escaped' =>
        '<!doctype html><script><!--<script></script><title></script>'
        . '<iframe id="af-parser-script"></iframe>',
    'regression/script-comment-overlap' =>
        '<!doctype html><script><!--><script></script>'
        . '<iframe id="af-parser-script-overlap" srcdoc="&lt;script&gt;window.ran=1&lt;/script&gt;"></iframe>'
        . '</script>',
    'regression/integration-point-cdata' =>
        '<!doctype html><svg><foreignObject><![CDATA[x><style>]]></foreignObject></svg>'
        . '<iframe id="af-parser-cdata"></iframe>',
    'regression/foreign-end-p-cdata' =>
        '<!doctype html><svg></p><![CDATA[x>'
        . '<iframe id="af-parser-foreign-end" srcdoc="&lt;script&gt;window.ran=1&lt;/script&gt;"></iframe>',
    'regression/frameset-ignored-svg-cdata' =>
        '<!doctype html><frameset><svg><![CDATA[x>'
        . '<frame id="af-parser-frameset-svg" src="about:blank">]]>',
    'regression/select-ignored-svg-cdata' =>
        '<!doctype html><select><svg></select><![CDATA[x>'
        . '<iframe id="af-parser-select-svg" srcdoc="&lt;script&gt;window.ran=1&lt;/script&gt;"></iframe>',
    'regression/select-noframes-engine-differential' =>
        '<!doctype html><table><select></style><noframes><script><svg>'
        . '<noframes><xmp></noframes><iframe id="af-parser-select-noframes"></iframe>',
    'regression/frameset-plaintext' =>
        '<!doctype html><frameset><noframes></frameset></noframes><plaintext>'
        . '<frame id="af-parser-frameset">',
    'regression/select-script' =>
        '<!doctype html><select><script>/*</select>*/</script><plaintext></select>'
        . '<iframe id="af-parser-select"></iframe>',
    'regression/math-mtext-select-script-engine-differential' =>
        '<!doctype html><math><mtext><select><desc><p><svg><script></select>'
        . '<iframe id="af-parser-math-select-script"></iframe>',
    'regression/select-ignored-frameset-noframes' =>
        '<!doctype html><select><option><frameset>--><noframes></select>'
        . '<iframe data-decoy=">" id="af-parser-ignored-frameset"></iframe>',
];

foreach ($mandatoryCases as $id => $payload) {
    if (count($cases) >= $caseCount) {
        break;
    }

    $appendCase($id, $payload);
}

$foreignContexts = [
    ['<svg><g>', '</g></svg>'],
    ['<svg><foreignObject>', '</foreignObject></svg>'],
    ['<svg><desc>', '</desc></svg>'],
    ['<svg><title>', '</title></svg>'],
    ['<math><mtext>', '</mtext></math>'],
    ['<math><mi>', '</mi></math>'],
    ['<math><annotation-xml>', '</annotation-xml></math>'],
    ['<math><annotation-xml encoding="text/html">', '</annotation-xml></math>'],
    ['<math><annotation-xml><svg><mtext>', '</mtext></svg></annotation-xml></math>'],
    [
        '<svg><foreignObject><math><annotation-xml><svg><mo>',
        '</mo></svg></annotation-xml></math></foreignObject></svg>',
    ],
];
$rawTextOpeners = [
    '<script>',
    '<style>',
    '<title>',
    '<textarea>',
    '<xmp>',
    '<noembed>',
    '<noframes>',
    '<noscript>',
    '<plaintext>',
];
$stateTransitions = [
    '',
    '<!--',
    '-->',
    '--!>',
    '<![CDATA[x>',
    '<![CDATA[x>]]>',
    ']]>',
    '<script>',
    '</script>',
    '</style>',
    '</title>',
    '</textarea>',
    '</select>',
    '</frameset>',
    '</svg>',
    '</math>',
    '</p>',
    '</br>',
    '<font color=red>',
    '<p>',
    '<br>',
];
$treeBuilderTokens = [
    '<svg>',
    '</svg>',
    '<math>',
    '</math>',
    '</p>',
    '</br>',
    '<foreignObject>',
    '</foreignObject>',
    '<desc>',
    '<mtext>',
    '<annotation-xml>',
    '<annotation-xml encoding="text/html">',
    '<select>',
    '</select>',
    '<option>',
    '<frameset>',
    '</frameset>',
    '<noframes>',
    '<table>',
    '<template shadowrootmode="open">',
    '</template>',
    '<font color=red>',
    '<p>',
    '<br>',
    '<!--',
    '-->',
    '<![CDATA[x>',
    ']]>',
    '<script>',
    '</script>',
    '<style>',
    '</style>',
    '<title>',
    '</title>',
    '<plaintext>',
];

while (count($cases) < $caseCount) {
    $index = count($cases);
    $family = $random->between(0, 4);
    $payload = '<!doctype html>';

    if ($family === 0) {
        [$contextOpen, $contextClose] = $random->pick($foreignContexts);
        $payload .= $contextOpen;

        for ($token = 0; $token < $random->between(1, 4); ++$token) {
            $payload .= $random->pick($stateTransitions);
        }

        $payload .= $random->pick($rawTextOpeners);

        for ($token = 0; $token < $random->between(0, 3); ++$token) {
            $payload .= $random->pick($stateTransitions);
        }

        if ($random->between(0, 1) === 1) {
            $payload .= $contextClose;
        }

        $payload .= $frame($index);
        $payload .= $random->pick(['', $contextClose, '</script>', '</style>', ']]>']);
    } elseif ($family === 1) {
        $payload .= '<script>';
        $scriptTokens = [
            '<!--',
            '-->',
            '--!>',
            '<script>',
            '<SCRIPT>',
            '<script src=x>',
            '<script/>',
            '</script>',
            '</script/>',
            '>',
            '->',
            '<',
            '</scrip',
            'x',
        ];

        for ($token = 0; $token < $random->between(2, 9); ++$token) {
            $payload .= $random->pick($scriptTokens);
        }

        $payload .= $random->pick(['<title>', '<textarea>', '<style>', '<xmp>', '<noembed>']);
        $payload .= '</script>' . $frame($index);
    } elseif ($family === 2) {
        $dispatcherOpeners = [
            '<select>',
            '<select><option>',
            '<frameset>',
            '<frameset><noframes>',
            '<table><select>',
            '<svg><select>',
            '<math><mtext><select>',
        ];
        $payload .= $random->pick($dispatcherOpeners);

        for ($token = 0; $token < $random->between(1, 6); ++$token) {
            $payload .= $random->pick($treeBuilderTokens);
        }

        $payload .= $random->pick($rawTextOpeners);
        $payload .= $random->pick(['', '</select>', '</frameset>', '</noframes>', '</script>', '</svg>']);
        $payload .= $frame($index);
    } elseif ($family === 3) {
        [$contextOpen, $contextClose] = $random->pick($foreignContexts);
        $payload .= $contextOpen;
        $payload .= $random->pick([
            '<![CDATA[x>',
            '<![CDATA[x>]]>',
            '<![CDATA[<!--x>]]>',
            '<!x ">" >',
            '<?x ">" >',
            '<!--x-->',
            '<!--x--!>',
        ]);
        $payload .= $random->pick($rawTextOpeners);
        $payload .= $random->pick(['', ']]>', '-->', '</svg>', '</math>', $contextClose]);
        $payload .= $frame($index);
        $payload .= $random->pick(['', $contextClose, '</style>', '</script>']);
    } else {
        for ($token = 0; $token < $random->between(4, 16); ++$token) {
            $payload .= $random->pick($treeBuilderTokens);
        }

        $payload .= $frame($index);

        for ($token = 0; $token < $random->between(0, 4); ++$token) {
            $payload .= $random->pick($treeBuilderTokens);
        }
    }

    $appendCase(sprintf('generated/%03d/family-%d', $index, $family), $payload);
}

echo json_encode(
    [
        'seed' => $seed,
        'requestedCases' => $caseCount,
        'cases' => $cases,
    ],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
), "\n";
