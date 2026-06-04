@extends('layouts.app')

@section('title', '収集先登録 - PeerScope')
@section('page_title', '収集先登録')

@section('content')
    @include('watch_sources._form', [
        'action' => route('watch-sources.store'),
        'method' => null,
        'button' => '登録',
    ])
@endsection
