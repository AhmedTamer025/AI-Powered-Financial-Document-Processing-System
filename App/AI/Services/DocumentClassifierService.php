<?php

namespace App\AI\Services;

use App\AI\Agents\DocumentClassifierAgent;
use App\AI\Builders\ClassificationPromptBuilder;
use App\AI\DTOS\DocumentClassification;
use App\AI\DTOS\RawDocument;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\LocalImage;

class DocumentClassifierService
{
    public function __construct(
        private FileDispatcherService $dispatcher,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Classify Document
    |--------------------------------------------------------------------------
    */

    public function classify(
        string $path,
        string $businessId
    ): DocumentClassification
    {
        $extension = strtolower(
            pathinfo($path, PATHINFO_EXTENSION)
        );

        return match ($extension) {

            /*
            |--------------------------------------------------------------------------
            | Vision Classification
            |--------------------------------------------------------------------------
            */

            'pdf',
            'jpg',
            'jpeg',
            'png',
            'webp'
            => $this->classifyFile(
                $path,
                $extension,
                $businessId
            ),

            'doc',
            'docx',
            'xls',
            'xlsx',
            'csv',
            'ods'
            => $this->classifyText(
                $this->dispatcher->extract($path),
                $businessId
            ),

            /*
            |--------------------------------------------------------------------------
            | Unsupported
            |--------------------------------------------------------------------------
            */

            default => throw new \RuntimeException(
                "Unsupported file type: {$extension}"
            ),
        };
    }


    public function classifyDocument(
        RawDocument $document,
        ?string $businessId
    ): DocumentClassification {

        return $this->classifyText(
            $document,
            $businessId
        );
    }


    private function classifyFile(
        string $path,
        string $extension,
        string $businessId 
    ): DocumentClassification {

        $attachment = match ($extension) {

            'pdf'
                => Document::fromUrl(
                    'data:application/pdf;base64,' . base64_encode(
                        file_get_contents($path)
                    )
                )->as(basename($path)),

            default
                => new LocalImage($path),
        };

        $agent = new DocumentClassifierAgent(businessId: $businessId);

       
        $response = $agent->prompt(
            'Classify this financial document. Identify the business/account-holder name and validate it against the selected business and its registered owners using the database tool.',

            attachments: [$attachment],

            model: config(
                'ai.classification.model',
                'mistral-large-latest'
            ),

            timeout: (int) config(
                'ai.classification.timeout',
                120
            ),
        );

        /*
        |--------------------------------------------------------------------------
        | Log Raw AI Response
        |--------------------------------------------------------------------------
        */

        logger()->info(
            'Document classification raw response',
            [
                'file' => basename($path),
                'business_id' => $businessId,
                'response' => $response,
            ]
        );

        return $this->buildClassification(
            $response
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Classify Text
    |--------------------------------------------------------------------------
    */

    private function classifyText(
        RawDocument $document,
        string $businessId
    ): DocumentClassification {

        $agent = new DocumentClassifierAgent(businessId: $businessId);

        $response = $agent->prompt(
            ClassificationPromptBuilder::build(
                $document
            ),
            model: config(
                'ai.classification.model',
                'mistral-large-latest'
            ),
            timeout: (int) config(
                'ai.classification.timeout',
                120
            ),
        );

        /*
        |--------------------------------------------------------------------------
        | Log Raw AI Response
        |--------------------------------------------------------------------------
        */

        logger()->info(
            'Document classification raw response',
            [
                'file' => $document->fileName,
                'business_id' => $businessId,
                'response' => $response,
            ]
        );

        return $this->buildClassification(
            $response
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Build Classification
    |--------------------------------------------------------------------------
    */

    private function buildClassification(
        string $json
    ): DocumentClassification {

        /*
        |--------------------------------------------------------------------------
        | Clean Response
        |--------------------------------------------------------------------------
        */

        $json = trim($json);

        /*
        |--------------------------------------------------------------------------
        | Remove Markdown Code Fence
        |--------------------------------------------------------------------------
        */


        if (str_starts_with($json, '```')) {

            $json = preg_replace(
                '/^```(?:json)?\s*/i',
                '',
                $json
            );

    
            $json = preg_replace(
                '/\s*```$/',
                '',
                $json
            );

            $json = trim($json);
        }

        /*
        |--------------------------------------------------------------------------
        | Decode JSON
        |--------------------------------------------------------------------------
        */

        $data = json_decode(
            $json,
            true
        );

        /*
        |--------------------------------------------------------------------------
        | Handle Invalid JSON
        |--------------------------------------------------------------------------
        */

        if (
            !is_array($data) ||
            json_last_error() !== JSON_ERROR_NONE
        ) {

            logger()->error(
                'Invalid document classification JSON',
                [
                    'raw_response' => $json,
                    'json_error' => json_last_error_msg(),
                ]
            );

            throw new \RuntimeException(
                'Document classifier returned invalid JSON: '
                . json_last_error_msg()
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate document_type
        |--------------------------------------------------------------------------
        */

        if (
            !isset($data['document_type']) ||
            !is_string($data['document_type'])
        ) {

            logger()->error(
                'Document classification missing document_type',
                [
                    'classification' => $data,
                ]
            );

            throw new \RuntimeException(
                'Document classifier response is missing document_type.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Language
        |--------------------------------------------------------------------------
        */

        $language = $data['language'] ?? 'unknown';

        /*
        |--------------------------------------------------------------------------
        | Confidence
        |--------------------------------------------------------------------------
        */

        $confidence = $data['confidence'] ?? 0;

        if (!is_numeric($confidence)) {
            $confidence = 0;
        }

        /*
        |--------------------------------------------------------------------------
        | Return DTO
        |--------------------------------------------------------------------------
        */

        return new DocumentClassification(

            documentType: strtolower(
                trim(
                    $data['document_type']
                )
            ),

            language: strtolower(
                trim(
                    (string) $language
                )
            ),

            confidence: (float) $confidence,

            detectedBusinessName: trim(
                (string) (
                    $data['detected_business_name'] ?? ''
                )
            ),

            businessNameMatch:
                (bool) (
                    $data['business_name_match'] ?? false
                ),

            matchedOwner:
                isset($data['matched_owner'])
                    ? trim(
                        (string) $data['matched_owner']
                    )
                    : null,

            businessNameMatchReason: trim(
                (string) (
                    $data['business_name_match_reason'] ?? ''
                )
            ),

        );
    }
}
