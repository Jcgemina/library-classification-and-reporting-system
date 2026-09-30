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

$reviews = aiCourseValidateRelevanceReviews([
    ['course_id' => 10, 'verdict' => 'relevant', 'reason' => 'Both describe software development practices.'],
    ['course_id' => 11, 'verdict' => 'irrelevant', 'reason' => 'The book is about software while the course is about Philippine history.'],
], [10, 11]);
expectAiCourse($reviews[10]['verdict'] === 'relevant', 'Relevant model verdict was not preserved.');
expectAiCourse($reviews[11]['verdict'] === 'irrelevant', 'Irrelevant model verdict was not preserved.');
$filtered = aiCourseFilterRelevantCandidates([
    ['course_id' => 10, 'score' => 0.91],
    ['course_id' => 11, 'score' => 0.66],
], $reviews, 5);
expectAiCourse(count($filtered['reviewed']) === 2, 'All judged candidates should remain available for audit.');
expectAiCourse(count($filtered['relevant']) === 1 && $filtered['relevant'][0]['course_id'] === 10, 'Irrelevant high-scoring courses must be filtered from results.');
expectAiCourse($filtered['reviewed'][1]['relevance_reason'] === $reviews[11]['reason'], 'Candidate rationale was not retained.');

foreach ([
    static fn() => aiCourseValidateRelevanceReviews([], [10]),
    static fn() => aiCourseValidateRelevanceReviews([['course_id' => 12, 'verdict' => 'relevant', 'reason' => 'Wrong ID.']], [10]),
    static fn() => aiCourseValidateRelevanceReviews([['course_id' => 10, 'verdict' => 'maybe', 'reason' => 'Unknown label.']], [10]),
    static fn() => aiCourseValidateRelevanceReviews([['course_id' => 10, 'verdict' => 'relevant', 'reason' => '']], [10]),
] as $invalidReview) {
    try {
        $invalidReview();
    } catch (AiCourseException) {
        continue;
    }
    throw new RuntimeException('An invalid relevance response was accepted.');
}

echo "AI course service tests passed.\n";
