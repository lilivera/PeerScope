@extends('layouts.app')

@section('title', 'ユーザー登録 - PeerScope')
@section('page_title', 'ユーザー登録')

@section('content')
    @include('users._form', [
        'action' => route('users.store'),
        'method' => null,
        'button' => '登録',
    ])
@endsection
