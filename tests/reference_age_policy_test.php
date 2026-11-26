<?php
require_once __DIR__ . '/../includes/functions.php';

if (!isValidCalendarDate('2024-02-29') ||
    isValidCalendarDate('2025-02-29') ||
    isValidCalendarDate('2026-2-03')) {
    throw new RuntimeException('Reference policy effective dates must be valid ISO calendar dates.');
}

$assertStatus = static function (string $expected, ?int $year, array $policy, string $case): void {
    $assessment = assessReferenceAge($year, $policy, 2026);
    if ($assessment['status'] !== $expected) {
        throw new RuntimeException(
            $case . ' expected ' . $expected . ', received ' . $assessment['status'] . '.'
        );
    }
};

$assertStatus('missing_publication_data', null, ['direction' => 'within', 'years' => 5], 'Missing publication year');
$assertStatus('meets_policy', 2023, ['direction' => 'within', 'years' => 5], 'Outside warning window');
$assertStatus('approaching_policy_limit', 2022, ['direction' => 'within', 'years' => 5], 'Two years before policy limit');
$assertStatus('approaching_policy_limit', 2021, ['direction' => 'within', 'years' => 5], 'At the limit, one year before policy breach');
$assertStatus('approaching_policy_limit', 2026, ['direction' => 'within', 'years' => 1], 'Two years before a one-year policy limit');
$assertStatus('outside_policy', 2020, ['direction' => 'within', 'years' => 5], 'Recent-age policy exceeded');
$assertStatus('meets_policy', 2021, ['direction' => 'at_least', 'years' => 5], 'Inclusive minimum-age threshold');
$assertStatus('outside_policy', 2022, ['direction' => 'at_least', 'years' => 5], 'Minimum-age policy not reached');
$assertStatus('needs_verification', 2025, ['direction' => null, 'years' => null], 'Unconfigured policy');
$assertStatus('needs_verification', 2027, ['direction' => 'within', 'years' => 5], 'Future publication year');

echo "Reference-age policy assessment tests passed.\n";
