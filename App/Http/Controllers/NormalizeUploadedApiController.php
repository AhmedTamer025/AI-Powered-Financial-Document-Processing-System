<?php

namespace App\Http\Controllers;

use App\Jobs\NormalizeDocumentJob;
use App\Models\AiResult;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;

class NormalizeUploadedApiController extends Controller
{
    /**
     * Return documents that have been uploaded and are waiting
     * for normalization.
     */
    public function pending()
    {
        $documents = AiResult::query()
            ->with([
                'bankStatement.business',
                'financialStatement.business',
            ])
            ->where('status', DocumentStatus::Uploaded)
            ->latest()
            ->get()
            ->map(function ($aiResult) {
                $upload = $aiResult->bankStatement
                    ?? $aiResult->financialStatement;

                return [
                    'id' => $aiResult->id,

                    'file_name' =>
                        $upload->original_file_name ?? null,

                    'document_type' =>
                        $aiResult->document_type?->label(),

                    'status' =>
                        $aiResult->status?->label(),

                    'business_id' =>
                        $upload->business_id ?? null,

                    'business_name' =>
                        $upload->business->name ?? null,

                    'created_at' =>
                        $aiResult->created_at,
                ];
            });

        return response()->json([
            'message' => 'Pending documents retrieved successfully.',
            'documents' => $documents,
        ]);
    }

    /**
     * Manually start normalization for an uploaded document.
     *
     * Normally the new flow can start normalization automatically
     * from ProcessUploadedDocumentJob.
     */
    public function normalize(AiResult $aiResult)
    {
        if ($aiResult->status === DocumentStatus::Completed) {
            return response()->json([
                'message' => 'Document is already normalized.',
                'status' => $aiResult->status,
            ], 409);
        }

        if ($aiResult->status === DocumentStatus::Normalizing) {
            return response()->json([
                'message' => 'Normalization is already running.',
                'status' => $aiResult->status,
            ], 409);
        }

        if ($aiResult->status === DocumentStatus::Failed) {
            return response()->json([
                'message' =>
                    'This document failed validation and cannot be normalized.',
                'status' => $aiResult->status,
                'error' => $aiResult->error_message,
            ], 422);
        }

        NormalizeDocumentJob::dispatch(
            $aiResult->id
        );

        return response()->json([
            'message' => 'Normalization started successfully.',
            'normalization_id' => $aiResult->id,
            'status' => 'normalizing',
        ], 202);
    }

    /**
     * Return the current processing / normalization result.
     */
    public function show(AiResult $normalization)
    {
        return response()->json([
            'id' => $normalization->id,

            'status' =>
                $normalization->status?->label(),

            'document_type' =>
                $normalization->document_type?->label(),

            'overall_confidence' =>
                $normalization->overall_confidence,

            'processing_time_ms' =>
                $normalization->processing_time_ms,

            'warnings' =>
                $normalization->warnings,

            'raw_extraction' =>
                $normalization->raw_extraction,

            'normalized_result' =>
                $normalization->normalized_result,

            'error_message' =>
                $normalization->error_message,
        ]);
    }
}