<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Queries;

use App\Domain\Ingestion\DTOs\AssessmentSource;
use App\Domain\Ingestion\Enums\AttachmentStatus;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Ingestion\Models\SubmissionAttachment;
use App\Domain\Ingestion\SourceLinks;
use App\Domain\Ingestion\SourceRegistry;

/**
 * Where an accepted assessment came from: its source, its instance ID, a link to the submission on
 * that source (SourceLinks), and its attachments (the facility drawer, ARCHITECTURE.md §9.2.7), each fetched one
 * with the authenticated route that serves it (SECURITY.md §7).
 *
 * Lives in Ingestion because it reads submissions, which Reporting may not (hard rule 6).
 * It reads only the submission an assessment points at, which passed every hard rule: never a
 * quarantined one, and never the payload.
 */
final class AssessmentSourceQuery
{
    public function __construct(
        private readonly SourceRegistry $sources,
        private readonly SourceLinks $links,
    ) {}

    public function forSubmission(int $submissionId): ?AssessmentSource
    {
        return $this->forSubmissions([$submissionId])[$submissionId] ?? null;
    }

    /**
     * The same for many submissions at once, two queries in all: an export's chunk.
     * $absolute gives attachment links with the portal's address, for a file that leaves it.
     *
     * @param  list<int>  $submissionIds
     * @return array<int, AssessmentSource> by submission id
     */
    public function forSubmissions(array $submissionIds, bool $absolute = false): array
    {
        if ($submissionIds === []) {
            return [];
        }
        $attachments = [];
        SubmissionAttachment::query()
            ->whereIn('submission_id', $submissionIds)
            ->orderBy('id')
            ->get(['id', 'submission_id', 'filename', 'mime', 'size', 'status', 'reason'])
            ->each(function (SubmissionAttachment $attachment) use (&$attachments, $absolute): void {
                $submissionId = (int) $attachment->submission_id;
                $attachments[$submissionId][] = [
                    'id' => $attachment->id,
                    'filename' => (string) $attachment->filename,
                    'mime' => $attachment->mime,
                    'size' => $attachment->size,
                    'status' => $attachment->status->value,
                    'reason' => is_string($attachment->reason) ? $attachment->reason : null,
                    'url' => $attachment->status === AttachmentStatus::Fetched
                        ? route('admin.attachments.show', ['submission' => $submissionId, 'filename' => (string) $attachment->filename], absolute: $absolute)
                        : null,
                ];
            });

        $sources = [];
        foreach (Submission::query()->whereIn('id', $submissionIds)->get(['id', 'source_id', 'external_id', 'instance_id']) as $submission) {
            $key = $this->sources->keyOf((int) $submission->source_id);
            $sources[(int) $submission->id] = new AssessmentSource(
                $key,
                $key === null ? null : $this->sources->name($key),
                (string) $submission->instance_id,
                $key === null ? null : $this->links->submission($key, is_string($submission->external_id) ? $submission->external_id : null),
                $attachments[(int) $submission->id] ?? [],
            );
        }

        return $sources;
    }
}
