<?php
/**
 * Generate src/Resource/<Tag>Resource.php (typed wrappers) + ResourceRegistry.php
 * from the generated core Api classes. Parses each op's signature + @param
 * docblock, derives an alias (get->retrieve, strip the resource noun), classifies
 * list/create/retrieve/update/passthrough, and emits wrappers delegating to the
 * core Api (with typed-error mapping + array->model coercion).
 *
 * Run: php scripts/gen_resources.php   (after scripts/sync_core.php)
 */

$repo = dirname(__DIR__);
$apiDir = $repo . '/lib/Api';
$resDir = $repo . '/src/Resource';

$VERB_ALIAS = ['get' => 'retrieve'];
$RESERVED = ['raw', 'call', 'model', 'constructEvent'];
$ATTR_OVERRIDE = ['productsVariants' => 'variants'];

function splitCamel(string $s): array
{
    return preg_split('/(?<=[a-z0-9])(?=[A-Z])/', $s) ?: [$s];
}
function singular(string $w): string
{
    return preg_replace('/s$/i', '', $w);
}

/** Parse the params of `public function <op>(...)` -> [[name, hasDefault], ...] (contentType dropped). */
function parseParams(string $src, string $op): array
{
    if (!preg_match('/public function ' . preg_quote($op, '/') . '\s*\((.*?)\)\s*(?::|\{)/s', $src, $m)) {
        return [];
    }
    $raw = trim($m[1]);
    if ($raw === '') {
        return [];
    }
    $params = [];
    foreach (explode(',', $raw) as $piece) {
        if (!preg_match('/\$(\w+)/', $piece, $mm)) {
            continue;
        }
        $name = $mm[1];
        if ($name === 'contentType') {
            continue;
        }
        $params[] = ['name' => $name, 'hasDefault' => str_contains($piece, '=')];
    }
    return $params;
}

/** Find the @param type for $name in the docblock immediately above `function <op>`. */
function docParamType(string $src, string $op, string $name): ?string
{
    $pos = strpos($src, "public function {$op}(");
    if ($pos === false) {
        return null;
    }
    $start = strrpos(substr($src, 0, $pos), '/**');
    if ($start === false) {
        return null;
    }
    $doc = substr($src, $start, $pos - $start);
    if (preg_match('/@param\s+([\\\\A-Za-z0-9_]+)\s+\$' . preg_quote($name, '/') . '\b/', $doc, $m)) {
        return $m[1];
    }
    return null;
}

function deriveAlias(string $op, array $nounWords, array $verbAlias): string
{
    $words = splitCamel($op);
    $verb = $verbAlias[strtolower($words[0])] ?? $words[0];
    $rest = [];
    foreach (array_slice($words, 1) as $w) {
        $lw = strtolower($w);
        if (in_array($lw, $nounWords, true) || in_array(singular($lw), $nounWords, true)) {
            continue;
        }
        $rest[] = ucfirst($w);
    }
    $alias = lcfirst($verb) . implode('', $rest);
    return $alias ?: $op;
}

// Method templates (nowdoc — $ stays literal; %s filled by sprintf).
$TPL_LIST = <<<'PHP'
    public function %s(array $params = []): \Selectwin\Pagination\Paginator
    {
        $limit = (int) ($params['limit'] ?? 20);
        $offset = (int) ($params['offset'] ?? 0);
        return new \Selectwin\Pagination\Paginator(
            fn (int $l, int $o) => $this->call(fn () => $this->api->%s($l, $o)),
            $limit,
            $offset,
        );
    }
PHP;
$TPL_BODY = <<<'PHP'
    public function %s(array|object $body)
    {
        return $this->call(fn () => $this->api->%s($this->model($body, %s::class)));
    }
PHP;
$TPL_ID = <<<'PHP'
    public function %s(string $id)
    {
        return $this->call(fn () => $this->api->%s($id));
    }
PHP;
$TPL_ID_BODY = <<<'PHP'
    public function %s(string $id, array|object $body)
    {
        return $this->call(fn () => $this->api->%s($id, $this->model($body, %s::class)));
    }
PHP;
$TPL_PASS = <<<'PHP'
    public function %s(mixed ...$args)
    {
        return $this->call(fn () => $this->api->%s(...$args));
    }
PHP;
$TPL_CONSTRUCT_EVENT = <<<'PHP'
    /** Verify a webhook signature and return the typed event. */
    public function constructEvent(string $rawBody, ?string $signatureHeader, string $secret, array $options = []): \Selectwin\Webhook\Event
    {
        return \Selectwin\Webhook\WebhookSignature::constructEvent($rawBody, $signatureHeader, $secret, $options);
    }
PHP;

@mkdir($resDir, 0777, true);
$files = glob($apiDir . '/*Api.php');
sort($files);
$registry = [];
$totalOps = 0;

foreach ($files as $file) {
    $src = file_get_contents($file);
    $class = basename($file, '.php');            // e.g. CustomersApi
    $base = preg_replace('/Api$/', '', $class);  // Customers
    $resClass = $base . 'Resource';
    $attr = lcfirst($base);
    $attr = $ATTR_OVERRIDE[$attr] ?? $attr;

    // Real ops = methods N where "<N>Request" also exists.
    preg_match_all('/public function (\w+)\s*\(/', $src, $mm);
    $names = array_unique($mm[1]);
    $ops = array_values(array_filter($names, fn ($n) => in_array($n . 'Request', $names, true)));
    sort($ops);

    $nounWords = [];
    foreach (splitCamel($base) as $w) {
        $nounWords[] = strtolower($w);
        $nounWords[] = singular(strtolower($w));
    }

    $methods = [];
    $used = [];
    foreach ($ops as $op) {
        $params = parseParams($src, $op);
        $required = array_values(array_filter($params, fn ($p) => !$p['hasDefault']));
        $names2 = array_map(fn ($p) => $p['name'], $params);
        $hasPaging = in_array('limit', $names2, true) && in_array('offset', $names2, true);
        $ids = array_values(array_filter($required, fn ($p) => str_ends_with($p['name'], '_id')));
        $bodies = array_values(array_filter($required, function ($p) use ($src, $op) {
            $t = docParamType($src, $op, $p['name']);
            return $t !== null && str_contains($t, '\\Model\\');
        }));

        $alias = deriveAlias($op, $nounWords, $VERB_ALIAS);
        if (in_array($alias, $RESERVED, true) || (isset($used[$alias]) && $used[$alias] !== $op)) {
            $alias = $op;
        }
        $used[$alias] = $op;
        $totalOps++;

        if ($hasPaging && count($required) === 0) {
            $methods[] = sprintf($TPL_LIST, $alias, $op);
        } elseif (count($required) === 1 && count($bodies) === 1) {
            $model = docParamType($src, $op, $bodies[0]['name']);
            $methods[] = sprintf($TPL_BODY, $alias, $op, $model);
        } elseif (count($required) === 1 && count($ids) === 1) {
            $methods[] = sprintf($TPL_ID, $alias, $op);
        } elseif (count($required) === 2 && count($ids) === 1 && count($bodies) === 1) {
            $model = docParamType($src, $op, $bodies[0]['name']);
            $methods[] = sprintf($TPL_ID_BODY, $alias, $op, $model);
        } else {
            $methods[] = sprintf($TPL_PASS, $alias, $op);
        }
    }

    if ($attr === 'webhooks') {
        $methods[] = $TPL_CONSTRUCT_EVENT;
    }

    $body = implode("\n\n", $methods);
    $php = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Selectwin\\Resource;\n\n"
        . "/**\n * GENERATED by scripts/gen_resources.php — DO NOT EDIT.\n"
        . " * Typed wrappers over \\Selectwin\\Api\\{$class}.\n */\n"
        . "final class {$resClass} extends AbstractResource\n{\n{$body}\n}\n";
    file_put_contents($resDir . "/{$resClass}.php", $php);
    $registry[$attr] = [$class, $resClass];
}

// Registry.
$rows = '';
foreach ($registry as $attr => [$apiClass, $resClass]) {
    $rows .= "        '{$attr}' => [\\Selectwin\\Api\\{$apiClass}::class, \\Selectwin\\Resource\\{$resClass}::class],\n";
}
$reg = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Selectwin\\Resource;\n\n"
    . "/**\n * GENERATED by scripts/gen_resources.php — DO NOT EDIT.\n"
    . " * attribute => [core Api class, DX resource class].\n */\n"
    . "final class ResourceRegistry\n{\n    /** @var array<string, array{0: class-string, 1: class-string}> */\n"
    . "    public const MAP = [\n{$rows}    ];\n}\n";
file_put_contents($resDir . '/ResourceRegistry.php', $reg);

echo 'wrote ' . count($registry) . " resources, {$totalOps} ops -> src/Resource/\n";
