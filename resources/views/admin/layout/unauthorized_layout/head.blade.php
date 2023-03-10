<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="robots" content="none" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
<meta name="description" content="admin login">
<title>Admin - {{ Voyager::setting("admin.title") }}</title>
<link rel="stylesheet" href="{{ voyager_asset('css/app.css') }}">
@if (__('voyager::generic.is_rtl') == 'true')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-rtl/3.4.0/css/bootstrap-rtl.css">
    <link rel="stylesheet" href="{{ voyager_asset('css/rtl.css') }}">
@endif
<style>
    body {
        background-image:url('{{ Voyager::image( Voyager::setting("admin.bg_image"), voyager_asset("images/bg.jpg") ) }}');
        background-color: {{ Voyager::setting("admin.bg_color", "#FFFFFF" ) }};
    }
    body.login .login-sidebar {
        border-top:5px solid {{ config('voyager.primary_color','#22A7F0') }};
    }
    @media (max-width: 767px) {
        body.login .login-sidebar {
            border-top:0px !important;
            border-left:5px solid {{ config('voyager.primary_color','#22A7F0') }};
        }
    }
    body.login .form-group-default.focused{
        border-color:{{ config('voyager.primary_color','#22A7F0') }};
    }
    .login-button, .bar:before, .bar:after{
        background:{{ config('voyager.primary_color','#22A7F0') }};
    }
    .remember-me-text{
        padding:0 5px;
    }
</style>

<link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,700" rel="stylesheet">
