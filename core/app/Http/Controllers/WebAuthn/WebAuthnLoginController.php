<?php
namespace App\Http\Controllers\WebAuthn;
use App\Http\Controllers\Controller;
use Laragear\WebAuthn\Http\Requests\AssertionRequest;
use Laragear\WebAuthn\Http\Requests\AssertedRequest;
class WebAuthnLoginController extends Controller {
    public function options(AssertionRequest $request) { return $request->toVerify(); }
    public function login(AssertedRequest $request) { $request->login(); return response()->json(['status' => 'success']); }
}
