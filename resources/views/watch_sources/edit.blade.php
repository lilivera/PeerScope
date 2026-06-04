@extends('layouts.app')

@section('title', '収集先編集 - PeerScope')
@section('page_title', '収集先編集')

@section('content')
    @include('watch_sources._form', [
        'action' => route('watch-sources.update', $source),
        'method' => 'PUT',
        'button' => '更新',
    ])
@endsection
