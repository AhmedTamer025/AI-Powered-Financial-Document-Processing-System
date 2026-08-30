<!DOCTYPE html>

<html>

<head>

<title>
Upload Financial Documents
</title>


<script src="https://cdn.tailwindcss.com"></script>


</head>



<body class="bg-gray-100">



<div class="max-w-xl mx-auto mt-10 bg-white p-6 rounded shadow">



<h1 class="text-2xl font-bold mb-6">
Upload Document
</h1>





@if($errors->any())

<div class="bg-red-100 p-3 rounded mb-4">

<ul>

@foreach($errors->all() as $error)

<li>
{{ $error }}
</li>

@endforeach


</ul>

</div>

@endif







<form

action="{{ route('upload') }}"

method="POST"

enctype="multipart/form-data"

>


@csrf





<label class="block mb-2 font-semibold">

Business

</label>




<select

name="business_id"

class="border p-2 w-full rounded mb-5"

>


<option value="">
Select Business
</option>




@foreach($businesses as $business)


<option value="{{ $business->id }}">

{{ $business->name }}

</option>



@endforeach



</select>






<label class="block mb-2 font-semibold">

Files

</label>




<input

type="file"

name="files[]"

multiple

class="border p-2 w-full mb-5"

/>






<button

type="submit"

class="bg-blue-600 text-white px-5 py-2 rounded"

>

Upload

</button>





</form>



</div>



</body>

</html>