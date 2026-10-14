<?php
namespace App\Http\Controllers\WebAuthn;
use App\Http\Controllers\Controller;
use Laragear\WebAuthn\Http\Requests\AttestationRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;
class WebAuthnRegisterController extends Controller {
    public function options(AttestationRequest $request) { return $request->toCreate(); }
    public function register(AttestedRequest $request) { $request->save(); return response()->json(['status' => 'success']); }
}
