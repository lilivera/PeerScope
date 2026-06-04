@extends('layouts.app')

@section('title', '会社登録 - PeerScope')
@section('page_title', '会社登録')

@section('content')
    @include('companies._form', [
        'action' => route('companies.store'),
        'method' => null,
        'button' => '登録',
    ])
@endsection
