<?php

namespace App\AI\DTOS;


class DocumentClassification
{

    public function __construct(

        public string $documentType,

        public string $language,

        public float $confidence,

        public string $detectedBusinessName = '',

        public ?string $matchedOwner,
        
        public bool $businessNameMatch = true,

      

       


        public string $businessNameMatchReason = ''

    )
    {

    }

}
