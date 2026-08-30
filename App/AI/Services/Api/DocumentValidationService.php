<?php

namespace App\AI\Services\Api;

use App\AI\Services\DocumentClassifierService;
use App\AI\Services\FileDispatcherService;
use App\Jobs\ProcessUploadedDocumentJob;
use App\Models\Business;
use Illuminate\Support\Facades\Storage;

class DocumentValidationService
{
    public function __construct(
        private FileDispatcherService $dispatcher,
        private DocumentClassifierService $classifier,
    ) {}

    /**
     * Validate a file that has already been uploaded to storage.
     *
     * Flow:
     *
     * stored file
     *     ↓
     * classification
     *     ↓
     * extraction
     *     ↓
     * expected type check
     *     ↓
     * ProcessUploadedDocumentJob
     */
    public function validateStored(
        string $storedPath,
        string $businessId
    ): array {

        $originalName = $this->extractOriginalFileName(
            $storedPath
        );
        if (!Storage::disk('local')->exists($storedPath)) {
            throw new \RuntimeException(
                "Stored file not found: {$storedPath}"
            );
        }

        $absolutePath = Storage::disk('local')->path(
            $storedPath
        );


        $classification= $this->classifier->classify(
            $absolutePath,
            $businessId
        );



        $business= Business::query()
            ->select([
                'id',
                'name',
            ])
            ->find($businessId);
        /*
         * Reject the file if its detected type does not
         * match the type expected by the client.
         */
        if ($classification->documentType == 'unsupported') {
            Storage::disk('local')->delete(
                $storedPath
            );

            return [
                'accepted' => false,
                'file' => $originalName,
                'detected_type' => $classification->documentType,
                'detected_language' => $classification->language,
                'expected_business_name' => $business?->name,
                'detected_business_name' => $classification->detectedBusinessName,
                'business_name_match' => $classification->businessNameMatch,
                'matched_owner' =>$classification->matchedOwner,
                'business_name_match_reason' => $classification->businessNameMatchReason,
                'confidence' => $classification->confidence,
                'message' =>
                    sprintf(
                        'Unsupported document type: %s.',
                        $classification->documentType
                    ),
            ];
        }

        /*
         * Reject the file if it does not belong
         * to the selected business.
         */
        if (! $classification->businessNameMatch) {

            Storage::disk('local')->delete(
                $storedPath
            );

            return [
                'accepted' => false,
                'file' => $originalName,
                'detected_type' => $classification->documentType,
                'detected_language' => $classification->language,
                'expected_business_name' => $business->name,
                'detected_business_name' => $classification->detectedBusinessName,
                'business_name_match' => false,
                'matched_owner' =>$classification->matchedOwner,
                'business_name_match_reason' => $classification->businessNameMatchReason,
                'confidence' => $classification->confidence,
                'message' => $classification->businessNameMatchReason,
            ];
        }
        /*
         * Extract the document content.
         */
        $document = $this->dispatcher->extract(
            $absolutePath
        );

        /*
         * The file passed validation.
         *
         * The job is responsible for creating the database
         * records and continuing the processing pipeline.
         */
        $extractionUsage = $this->dispatcher->lastUsage();
        ProcessUploadedDocumentJob::dispatch(
            businessId: $businessId,
            storedPath: $storedPath,
            originalName: $originalName,
            expectedType: $classification->documentType,
            rawDocument: $document,
            extractionUsage: $extractionUsage
        );





        return [
            'file' => $originalName,
            'status' => 'accepted',
            'detected_type' => $classification->documentType,
            'detected_language' => $classification->language,
            'expected_business_name' => $business?->name,
            'detected_business_name' => $classification->detectedBusinessName,
            'business_name_match' => $classification->businessNameMatch,
            'matched_owner' =>$classification->matchedOwner,
            'business_name_match_reason' => $classification->businessNameMatchReason,
            'confidence' => $classification->confidence,
        ];
    }

    private function extractOriginalFileName(
        string $reference
    ): ?string {

        $parts = explode('~', $reference, 2);

        if (count($parts) !== 2) {
            return null;
        }

        $originalName = trim($parts[1]);

        return $originalName !== ''
            ? $originalName
            : null;
    }
}
