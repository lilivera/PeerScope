@extends('layouts.app')

@section('title', '会社編集 - PeerScope')
@section('page_title', '会社編集')

@section('content')
    @include('companies._form', [
        'action' => route('companies.update', $company),
        'method' => 'PUT',
        'button' => '更新',
    ])
@endsection
