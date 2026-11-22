@extends('errors::minimal')

@section('title', __('mgs.err.not_found'))
@section('code', '404')
@section('message', $exception->getMessage() ?? __('mgs.err.not_found'))
