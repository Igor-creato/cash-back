param(
    [switch] $IncludeThirdParty,
    [switch] $ShowRedactedLines
)

$ErrorActionPreference = 'Stop'

$rules = @(
    @{ Name = 'private-key-block'; Regex = '-----BEGIN (RSA |DSA |EC |OPENSSH |PGP )?PRIVATE KEY-----'; Strong = $true },
    @{ Name = 'aws-access-key-id'; Regex = 'AKIA[0-9A-Z]{16}'; Strong = $true },
    @{ Name = 'github-token'; Regex = 'gh[pousr]_[A-Za-z0-9_]{36,}'; Strong = $true },
    @{ Name = 'openai-token'; Regex = 'sk-(proj-)?[A-Za-z0-9_-]{20,}'; Strong = $true },
    @{ Name = 'slack-token'; Regex = 'xox[baprs]-[A-Za-z0-9-]{20,}'; Strong = $true },
    @{ Name = 'telegram-bot-token'; Regex = '[0-9]{8,10}:[A-Za-z0-9_-]{35}'; Strong = $true },
    @{ Name = 'jwt-token'; Regex = 'eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+'; Strong = $true },
    @{ Name = 'secret-like-literal-assignment'; Regex = '(?i)(api[_-]?key|secret|token|password|passwd|passphrase|client[_-]?secret|access[_-]?token|refresh[_-]?token|authorization|bearer)[A-Za-z0-9_\-""''\s]*(?:=|=>|:)\s*["''](?<Value>[^"'']{16,})["'']'; Strong = $false }
)

function Test-SecretLikeValue {
    param([string] $Value)

    if ($Value -match '^(https?://|[a-z0-9_]+$)' -or $Value -match '\s') {
        return $false
    }

    $classes = 0
    if ($Value -cmatch '[a-z]') { $classes++ }
    if ($Value -cmatch '[A-Z]') { $classes++ }
    if ($Value -match '[0-9]') { $classes++ }
    if ($Value -match '[^A-Za-z0-9]') { $classes++ }

    return ($Value.Length -ge 20 -and $classes -ge 3)
}

$exclude = if ($IncludeThirdParty) {
    '^(composer\.lock|package-lock\.json|pnpm-lock\.yaml|yarn\.lock)$'
} else {
    '^(vendor/|node_modules/|composer\.lock|package-lock\.json|pnpm-lock\.yaml|yarn\.lock)$'
}

$files = & git ls-files --cached --others --exclude-standard | Where-Object { $_ -notmatch $exclude }
$findings = New-Object System.Collections.Generic.List[object]

foreach ($file in $files) {
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) {
        continue
    }

    $lineNumber = 0
    Get-Content -LiteralPath $file -ErrorAction SilentlyContinue | ForEach-Object {
        $lineNumber++
        $line = $_

        foreach ($rule in $rules) {
            if ($line -match $rule.Regex) {
                if (-not $rule.Strong -and -not (Test-SecretLikeValue -Value $Matches['Value'])) {
                    continue
                }

                $finding = [ordered]@{
                    Rule = $rule.Name
                    File = $file
                    Line = $lineNumber
                }

                if ($ShowRedactedLines) {
                    $finding.RedactedLine = $line -replace '(["''])[^"'']{8,}(["''])', '$1<redacted>$2'
                }

                $findings.Add([pscustomobject]$finding)
                break
            }
        }
    }
}

Write-Output ('files_scanned=' + @($files).Count)
Write-Output ('findings=' + $findings.Count)

if ($findings.Count -gt 0) {
    $findings | Sort-Object Rule, File, Line | Format-Table -AutoSize
    exit 1
}
