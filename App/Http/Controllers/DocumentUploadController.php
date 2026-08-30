<?php

namespace App\Http\Controllers;

use App\AI\Services\Api\DocumentValidationService;
use App\Models\UploadedFileHash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class DocumentUploadController extends Controller
{
    protected string $uploadFolder = 'uploads';

    public function __construct(
        private DocumentValidationService $validationService
    ) {}

    /**
     * Step 1: Prepare the upload.
     *
     * The client sends the original file name.
     *
     * The server generates:
     * - a unique UUID
     * - a reference = UUID~original_filename
     * - a stored file name
     * - a storage path
     * - a temporary signed upload URL
     */
    public function prepare(Request $request)
    {
        $request->validate([
            'file_name' => 'required|string|max:255',
        ]);

        $originalFileName = $request->input('file_name');

        $uuid = (string) Str::uuid();

        $reference = "{$uuid}~{$originalFileName}";

        $storedFileName = $reference;

        $storedPath = "{$this->uploadFolder}/{$storedFileName}";

        $uploadUrl = URL::temporarySignedRoute(
            'documents.upload',
            now()->addMinutes(15),
            [
                'reference' => $reference,
            ]
        );

        return response()->json([
            'reference' => $reference,
            'stored_file_name' => $storedFileName,
            'stored_path' => $storedPath,
            'original_file_name' => $originalFileName,
            'upload_url' => $uploadUrl,
            'expires_in' => 900,
        ]);
    }

    /**
     * Step 2: Upload the actual file.
     *
     * This endpoint:
     * - receives the raw file
     * - generates SHA-256 hash from file content
     * - checks if the exact same file was uploaded before
     * - rejects duplicate files even if the filename is different
     * - stores new files
     */
    public function upload(
        Request $request,
        string $reference
    ) {
        $content = $request->getContent();

        if ($content === '') {
            return response()->json([
                'message' => 'Request body is empty.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Generate hash from actual file content
        |--------------------------------------------------------------------------
        |
        | Same content = same hash
        | Different filename does not matter.
        |
        */

        $fileHash = hash('sha256', $content);

        /*
        |--------------------------------------------------------------------------
        | Check if this exact file was uploaded before
        |--------------------------------------------------------------------------
        */

        $existingFile = UploadedFileHash::where(
            'file_hash',
            $fileHash
        )->first();

        if ($existingFile) {
            return response()->json([
                'message' => 'This exact file has already been uploaded.',
                'reference' => $reference,
            ], 409);
        }

        $storedPath = "{$this->uploadFolder}/{$reference}";

        try {
            /*
            |--------------------------------------------------------------------------
            | Save the file exactly as before
            |--------------------------------------------------------------------------
            */

            Storage::disk('local')->put(
                $storedPath,
                $content
            );

            /*
            |--------------------------------------------------------------------------
            | Save its hash
            |--------------------------------------------------------------------------
            |
            | file_hash must have a UNIQUE index in the database.
            |
            */

            UploadedFileHash::create([
                'file_hash' => $fileHash,
                'reference' => $reference,
                'stored_path' => $storedPath,
            ]);

        } catch (\Illuminate\Database\QueryException $e) {

            /*
            |--------------------------------------------------------------------------
            | Another request may have uploaded the same file simultaneously.
            |--------------------------------------------------------------------------
            */

            if (Storage::disk('local')->exists($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }

            $existingFile = UploadedFileHash::where(
                'file_hash',
                $fileHash
            )->first();

            if ($existingFile) {
                return response()->json([
                    'message' => 'This exact file has already been uploaded.',
                    'reference' => $reference,
                ], 409);
            }

            throw $e;

        } catch (\Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | If anything fails, remove the stored file.
            |--------------------------------------------------------------------------
            */

            if (Storage::disk('local')->exists($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $e;
        }

        return response()->json([
            'message' => 'File uploaded successfully.',
            'reference' => $reference,
            'stored_path' => $storedPath,
        ]);
    }

    /**
     * Step 3: Complete the upload and start document processing.
     */
    public function complete(Request $request)
    {
        $request->validate([
            'reference' => 'required|string|max:500',
            'business_id' => 'required|exists:businesses,id',
        ]);

        $reference = $request->input('reference');
        $businessId = $request->input('business_id');
        $storedFileName = $reference;

        $storedPath = "{$this->uploadFolder}/{$storedFileName}";

        if (!Storage::disk('local')->exists($storedPath)) {
            return response()->json([
                'message' => 'Uploaded file was not found.',
                'reference' => $reference,
            ], 404);
        }

        $result = $this->validationService->validateStored(
            storedPath: $storedPath,
            businessId: $businessId
        );

        return response()->json([
            'message' =>
                ($result['status'] ?? null) === 'accepted'
                    ? 'Document processing started.'
                    : 'Document was rejected.',

            'reference' => $reference,

            'document' => $result,
        ]);
    }
}