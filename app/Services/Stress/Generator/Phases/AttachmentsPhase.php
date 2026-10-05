<?php

namespace App\Services\Stress\Generator\Phases;

use App\Models\Attachment;
use App\Models\GeneralExchange;
use App\Models\GeneralExpense;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\ProjectCostReceipt;
use App\Services\Stress\Generator\StressBuildContext;
use Illuminate\Support\Facades\Storage;

/**
 * Small, harmless, valid files (PDF / PNG / JPEG — the only types the
 * upload fields accept) for the ~1.5% of financial records the flow phase
 * selected, stored and named exactly like AttachmentUploadService:
 * `{dir}/{prefix}_{attachmentId}_{Ymd}_{int amount}.{ext}` on the
 * `attachments` disk — which, under the stress profile, resolves inside the
 * stress storage root only. A soft-deleted parent has a soft-deleted
 * attachment whose file is kept, as the real delete paths do.
 */
final class AttachmentsPhase
{
    public const PARENT_CLASSES = [
        'project_cost_receipts' => ProjectCostReceipt::class,
        'project_cost_budgets' => ProjectCostBudget::class,
        'project_cost_budgets_payments' => ProjectCostBudgetsPayment::class,
        'general_expenses' => GeneralExpense::class,
        'general_exchanges' => GeneralExchange::class,
    ];

    public int $files = 0;

    public int $bytes = 0;

    public function __construct(private readonly StressBuildContext $ctx) {}

    public function run(): void
    {
        $random = $this->ctx->random->fork('attachments');
        $disk = Storage::disk(Attachment::DISK_ATTACHMENTS);

        foreach ($this->ctx->attachmentPlans as $plan) {
            $id = $this->ctx->nextId('attachments');
            [$ext, $mime] = $random->pick([['pdf', 'application/pdf'], ['pdf', 'application/pdf'], ['png', 'image/png'], ['jpg', 'image/jpeg']]);
            $content = $this->content($ext, $id);
            $name = $plan['prefix'].'_'.$id.'_'.str_replace('-', '', $this->ctx->calendar->date($plan['day'])).'_'.(int) $plan['amount'].'.'.$ext;
            $path = $plan['dir'].'/'.$name;

            $disk->put($path, $content);
            $this->files++;
            $this->bytes += strlen($content);

            $this->ctx->writer->add('attachments', [
                'id' => $id,
                'attachable_type' => self::PARENT_CLASSES[$plan['table']],
                'attachable_id' => $plan['id'],
                'file_name' => $name,
                'file_path' => $path,
                'disk' => Attachment::DISK_ATTACHMENTS,
                'file_type' => $mime,
                'file_size' => strlen($content),
                'notes' => null,
                'created_by' => $plan['by'],
                'updated_by' => $plan['by'],
                'created_at' => $plan['at'],
                'updated_at' => $plan['deleted_at'] ?? $plan['at'],
                'deleted_at' => $plan['deleted_at'],
            ]);

            if ($plan['deleted_at'] !== null) {
                $this->ctx->journal->deleted('attachments', $id, (int) $plan['delete_by']);
            }
        }

        $this->ctx->writer->flushAll();
    }

    private function content(string $ext, int $id): string
    {
        if ($ext === 'pdf') {
            $text = 'STRESS TEST - SYNTHETIC DOCUMENT #'.$id.' - NOT A REAL RECEIPT';
            $stream = 'BT /F1 11 Tf 20 100 Td ('.$text.') Tj ET';

            return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
                ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 420 144]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n"
                .'4 0 obj<</Length '.strlen($stream).">>stream\n".$stream."\nendstream endobj\n"
                ."5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
        }

        $image = imagecreatetruecolor(160, 60);
        imagefill($image, 0, 0, imagecolorallocate($image, 240, 240, 240));
        imagestring($image, 3, 8, 10, 'STRESS SYNTHETIC', imagecolorallocate($image, 30, 30, 30));
        imagestring($image, 3, 8, 30, '#'.$id, imagecolorallocate($image, 30, 30, 30));

        ob_start();
        $ext === 'png' ? imagepng($image) : imagejpeg($image, null, 70);
        $data = (string) ob_get_clean();
        imagedestroy($image);

        return $data;
    }
}
