<?php
require_once __DIR__ . '/../includes/functions.php';

$validIsbns = [
    '0-306-40615-2' => '0306406152',
    '0-8044-2957-x' => '080442957X',
    '978-0-306-40615-7' => '9780306406157',
    '978 0 306 40615 7' => '9780306406157',
    '9790000000001' => '9790000000001',
];

foreach ($validIsbns as $input => $normalized) {
    if (normalizeIsbn($input) !== $normalized || !isValidIsbn($input)) {
        throw new RuntimeException('Expected valid ISBN to normalize and pass checksum validation: ' . $input);
    }
}

$invalidIsbns = [
    '0306406153',
    '0804429571',
    '9780306406158',
    '978030640615X',
    '1230000000000',
    '978-0-306-40615-7-extra',
];

foreach ($invalidIsbns as $input) {
    if (isValidIsbn($input)) {
        throw new RuntimeException('Expected invalid ISBN to fail checksum validation: ' . $input);
    }
}

echo "ISBN checksum tests passed.\n";
