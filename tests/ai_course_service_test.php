<?php
require_once __DIR__ . '/../includes/ai_course_service.php';

function expectAiCourse(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

expectAiCourse(aiCourseNormalizeField("  loops\n and   functions ") === 'loops and functions', 'Text normalization failed.');
expectAiCourse(aiCourseSourceHash('book-v1', 'same text', 'model-a') !== aiCourseSourceHash('book-v1', 'same text', 'model-b'), 'Model changes must invalidate embeddings.');
expectAiCourse(abs(aiCourseCosineSimilarity([1.0, 0.0], [1.0, 0.0]) - 1.0) < 1e-9, 'Identical vectors should score 1.');
expectAiCourse(abs(aiCourseCosineSimilarity([1.0, 0.0], [0.0, 1.0])) < 1e-9, 'Orthogonal vectors should score 0.');
expectAiCourse(abs(aiCourseCosineSimilarity([1.0, 0.0], [-1.0, 0.0]) + 1.0) < 1e-9, 'Opposite vectors should score -1.');
expectAiCourse(aiCourseValidateVector([0.25, -0.5], 2) === [0.25, -0.5], 'Valid vectors should be normalized to floats.');

foreach ([
    static fn() => aiCourseCosineSimilarity([1.0], [1.0, 0.0]),
    static fn() => aiCourseCosineSimilarity([0.0, 0.0], [1.0, 0.0]),
    static fn() => aiCourseValidateVector(['not a number']),
    static fn() => aiCourseValidateVector([1.0], 2),
] as $invalidCase) {
    try {
        $invalidCase();
    } catch (Throwable $exception) {
        continue;
    }
    throw new RuntimeException('An invalid vector case was accepted.');
}

echo "AI course service tests passed.\n";
