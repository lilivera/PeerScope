@extends('layouts.app')

@section('title', 'ユーザー編集 - PeerScope')
@section('page_title', 'ユーザー編集')

@section('content')
    @include('users._form', [
        'action' => route('users.update', $user),
        'method' => 'PUT',
        'button' => '更新',
    ])
@endsection
