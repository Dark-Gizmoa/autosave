<?php
declare(strict_types=1);

/**
 * autosave_new.php (Firefly III v6.4.16)
 *
 * Features:
 * 1) Load configuration from .env and CLI (CLI overrides .env)
 *    .env is resolved relative to the script directory by default
 * 2) Split transactions are processed individually
 * 3) Minimum balance check:
 *    - If the source account balance AFTER the original transaction
 *      is <= MIN_BALANCE, auto-save is skipped
 *    - Transactions are skipped ONLY based on TAGS matching EXCLUDE_KEYWORDS
 * 4) Original transaction and auto-save transaction are linked
 *    using transaction-links (same behavior as original autosave script)
 * 5) Duplicate protection:
 *    - If the original transaction journal is already part of any link,
 *      auto-save is skipped (idempotent behavior)
 * 6) Notes/comment:
 *    "bezieht sich auf [{ID}], {Original description}"
 * 7) Tags on auto-save transaction:
 *    - Public tag (AUTOSAVE_TAG, default: autosave)
 *    - Internal fixed tag: __autosave__
 *
 * Server-side filtering:
 * Transactions are loaded via:
 * /accounts/{id}/transactions?start=...&end=...&type=withdrawal
 *
 * Important API details:
 * - transaction-links endpoint uses hyphen: /transaction-links
 * - link payload requires inward_id / outward_id
 */

//////////////////////
// CLI + .env config //
//////////////////////

function parseEnvFile(string $path): array
{
    if (!is_file($path)) return [];

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $out = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;

        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $k = trim($k);
        $v = trim($v);
        $v = trim($v, "\"'");

        if ($k !== '') {
            $out[$k] = $v;
        }
    }

    return $out;
}

function parseCli(array $argv): array
{
    $opts = getopt("", [
        "env::",
        "firefly-url::",
        "firefly-token::",
        "account::",
        "destination::",
        "amount::",
        "days::",
        "dry-run::",
        "min-balance::",
        "exclude-keywords::",
        "only-type::",
        "verbose::",
        "link-type-id::",
        "link-type-name::",
        "autosave-tag::",
    ]);

    $map = [
        "firefly-url" => "FIREFLY_URL",
        "firefly-token" => "FIREFLY_TOKEN",
        "account" => "ACCOUNT",
        "destination" => "DESTINATION",
        "amount" => "AMOUNT",
        "days" => "DAYS",
        "dry-run" => "DRY_RUN",
        "min-balance" => "MIN_BALANCE",
        "exclude-keywords" => "EXCLUDE_KEYWORDS",
        "only-type" => "ONLY_TYPE",
        "verbose" => "VERBOSE",
        "env" => "ENV_FILE",
        "link-type-id" => "LINK_TYPE_ID",
        "link-type-name" => "LINK_TYPE_NAME",
        "autosave-tag" => "AUTOSAVE_TAG",
    ];

    $out = [];
    foreach ($opts as $k => $v) {
        $key = $map[$k] ?? null;
        if ($key === null) continue;

        if (is_array($v)) {
            $v = end($v);
        }

        $out[$key] = ($v === false) ? "true" : (string)$v;
    }

    return $out;
}

function mergedConfig(array $env, array $cli): array
{
    // CLI values override .env values
    return array_merge($env, $cli);
}

function cfg(array $c, string $key, ?string $default = null): ?string
{
    return array_key_exists($key, $c) ? (string)$c[$key] : $default;
}

function cfgBool(array $c, string $key, bool $default = false): bool
{
    $v = cfg($c, $key, null);
    if ($v === null) return $default;

    return in_array(strtolower(trim($v)), ["1","true","yes","y","on"], true);
}

function cfgFloat(array $c, string $key, float $default = 0.0): float
{
    $v = cfg($c, $key, null);
    if ($v === null) return $default;

    return (float)str_replace(",", ".", $v);
}

function cfgInt(array $c, string $key, int $default = 0): int
{
    $v = cfg($c, $key, null);
    if ($v === null) return $default;

    return (int)$v;
}

function println(string $s): void
{
    echo $s . PHP_EOL;
}

//////////////////////
// Firefly API client //
//////////////////////

final class FireflyClient
{
    public function __construct(
        private string $baseUrl,
        private string $token,
        private bool $verbose = false
    ) {
        $this->baseUrl = rtrim($this->baseUrl, "/");
    }

    private function request(string $method, string $path, array $query = [], ?array $jsonBody = null): array
    {
        $url = $this->baseUrl . "/api/v1" . $path;
        if (!empty($query)) {
            $url .= "?" . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Accept: application/json",
                "Content-Type: application/json",
                "Authorization: Bearer " . $this->token,
            ],
            CURLOPT_TIMEOUT => 60,
        ]);

        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody, JSON_UNESCAPED_SLASHES));
        }

        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("cURL error: " . $err);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException("Invalid JSON response (HTTP {$http})");
        }

        if ($http < 200 || $http >= 300) {
            $msg = $data["message"] ?? $data["errors"][0]["detail"] ?? "HTTP {$http}";
            throw new RuntimeException("Firefly API error: {$msg}");
        }

        return $data;
    }

    public function listAccountTransactions(int $accountId, string $start, string $end, ?string $type): array
    {
        return $this->request("GET", "/accounts/{$accountId}/transactions", [
            "start" => $start,
            "end" => $end,
            "type" => $type,
            "limit" => 500,
        ])["data"] ?? [];
    }

    public function storeTransaction(array $payload): array
    {
        return $this->request("POST", "/transactions", [], $payload);
    }

    public function listTransactionLinks(): array
    {
        return $this->request("GET", "/transaction-links", ["limit" => 500])["data"] ?? [];
    }

    public function storeTransactionLink(array $payload): void
    {
        $this->request("POST", "/transaction-links", [], $payload);
    }
}

//////////////////////
// Helper functions   //
//////////////////////

function roundUpDelta(float $amount, float $roundTo): float
{
    if ($roundTo <= 0) return 0.0;

    $rounded = ceil($amount / $roundTo) * $roundTo;
    $delta = round($rounded - $amount, 2);

    return $delta < 0.01 ? 0.0 : $delta;
}

function toIsoDateTime(string $s): string
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)
        ? $s . "T00:00:00+00:00"
        : $s;
}

function toDateOnly(string $iso): string
{
    return substr($iso, 0, 10);
}

/**
 * Check ONLY tags (exact match, case-insensitive) against EXCLUDE_KEYWORDS.
 */
function hasExcludedTagStrict(array $tx, array $excludeKeywords): bool
{
    if (!isset($tx["tags"]) || !is_array($tx["tags"])) {
        return false;
    }

    $exclude = array_flip(
        array_map(fn($v) => mb_strtolower(trim((string)$v)), $excludeKeywords)
    );

    foreach ($tx["tags"] as $tag) {
        $tag = mb_strtolower(trim((string)$tag));
        if ($tag !== "" && isset($exclude[$tag])) {
            return true;
        }
    }

    return false;
}

//////////////////////
// Main logic         //
//////////////////////

$cli = parseCli($argv);
$envFile = $cli["ENV_FILE"] ?? (__DIR__ . "/.env");
$cfg = mergedConfig(parseEnvFile($envFile), $cli);

$baseUrl = cfg($cfg, "FIREFLY_URL");
$token   = cfg($cfg, "FIREFLY_TOKEN");
$account = cfgInt($cfg, "ACCOUNT");
$dest    = cfgInt($cfg, "DESTINATION");
$roundTo = cfgFloat($cfg, "AMOUNT");
$days    = cfgInt($cfg, "DAYS");
$minBal  = cfgFloat($cfg, "MIN_BALANCE", 20);
$dryRun  = cfgBool($cfg, "DRY_RUN", false);
$type    = cfg($cfg, "ONLY_TYPE", "withdrawal");

$excludeKeywords = array_map("trim", explode(",", cfg($cfg, "EXCLUDE_KEYWORDS", "")));

$autosaveTag = cfg($cfg, "AUTOSAVE_TAG", "autosave");
$internalTag = "__autosave__";
$linkTypeId  = cfgInt($cfg, "LINK_TYPE_ID", 1);

if (!$baseUrl || !$token || !$account || !$dest || !$roundTo) {
    println("Missing required parameters. Check .env or CLI options.");
    exit(2);
}

$end = (new DateTimeImmutable())->format("Y-m-d");
$start = $days > 0
    ? (new DateTimeImmutable())->sub(new DateInterval("P{$days}D"))->format("Y-m-d")
    : "2000-01-01";

println("Auto-save started");

$ff = new FireflyClient($baseUrl, $token);

$groups = $ff->listAccountTransactions($account, $start, $end, $type);
$links = $ff->listTransactionLinks();

$linkedJournals = [];
foreach ($links as $l) {
    $a = $l["attributes"] ?? [];
    if (!empty($a["inward_id"]))  $linkedJournals[$a["inward_id"]]  = true;
    if (!empty($a["outward_id"])) $linkedJournals[$a["outward_id"]] = true;
}

foreach ($groups as $group) {
    foreach ($group["attributes"]["transactions"] ?? [] as $tx) {

        $desc = $tx["description"] ?? "";

        if (hasExcludedTagStrict($tx, $excludeKeywords)) {
            println("SKIP (Tag): \"{$desc}\" --> Excluded_Keyword found");
            continue;
        }

        $amount = abs((float)$tx["amount"]);
        $delta = roundUpDelta($amount, $roundTo);
        if ($delta <= 0) continue;

        if (($tx["source_balance_after"] ?? 999999) <= $minBal) continue;

        $jid = $tx["transaction_journal_id"] ?? null;
        if (!$jid || isset($linkedJournals[$jid])) continue;

        $dateIso = toIsoDateTime($tx["date"]);
        $dateOut = toDateOnly($dateIso);

        println("Autosave candidate: Original #{$jid} \"{$desc}\" amount={$amount} -> delta={$delta} date={$dateOut}");

        if ($dryRun) continue;

        $payload = [
            "transactions" => [[
                "type" => "transfer",
                "date" => $dateIso,
                "amount" => number_format($delta, 2, ".", ""),
                "source_id" => (string)$account,
                "destination_id" => (string)$dest,
                "description" => "Auto-save for transaction #{$jid}",
                "notes" => "bezieht sich auf [{$jid}], {$desc}",
                "tags" => [$autosaveTag, $internalTag],
            ]],
        ];

        $created = $ff->storeTransaction($payload);
        $newJid = $created["data"]["attributes"]["transactions"][0]["transaction_journal_id"];

        $ff->storeTransactionLink([
            "link_type_id" => $linkTypeId,
            "inward_id" => (int)$jid,
            "outward_id" => (int)$newJid,
        ]);

        println("OK: created auto-save transfer (Journal #{$newJid}) and linked to original #{$jid} \"{$desc}\"");
    }
}

println("Auto-save finished");
