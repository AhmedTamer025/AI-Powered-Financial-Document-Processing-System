<?php

namespace App\Http\Controllers;

use App\Jobs\NormalizeUploadedDocumentJob;
use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FileController extends Controller
{

    public function index()
    {
        $businesses = Business::select([
            'id',
            'name'
        ])->get();


        return view(
            'upload',
            compact('businesses')
        );
    }



    public function upload(Request $request)
    {

        $request->validate([

            'business_id'=>'required|exists:businesses,id',

            'files'=>'required|array|min:1',

            'files.*'=>[
                'required',
                'file',
                'max:10240'
            ]

        ]);



        $batchReference = Str::uuid();


        $documents=[];


        foreach($request->file('files') as $file)
        {


            $path = $file->store('uploads');



            NormalizeUploadedDocumentJob::dispatch(

                businessId:$request->business_id,

                path:$path,

                originalName:$file->getClientOriginalName(),

                batchReference:$batchReference

            );



            $documents[]=[

                'file_name'=>$file->getClientOriginalName(),

                'status'=>'queued'

            ];

        }




        return response()->json([

            'message'=>'Files uploaded successfully',

            'batch_reference'=>$batchReference,

            'documents'=>$documents

        ],202);


    }

}
