<?php

namespace App\Traits;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

trait ApiResponseTrait
{
    protected function success($message, $data = null, $status = 200): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            //            'user_time' => Carbon::now()->format('Y-m-d H:i:s'),
            //            'server_time' => now(),
        ], $status);
    }

    /**
     * Process failures and ensure an appropriate response and status code is returned.
     *
     * @param  true  $reportError  - if the caller has already logged the error they can request that it not be re-reported here
     */
    protected function failure($message, $status = 400, $errors = null, bool $reportError = true, $flag = null): \Illuminate\Http\JsonResponse
    {
        $code = (int) $status;
        $code = ($code >= 100 && $code <= 599) ? $code : 400;
        // Ensure every error is logged. If for some reason we're unable to process $message or $errors
        // ensure *something* is logged so we know an error occurred. The caller can request that the error
        // not be reported here if they've already logged the error in their local scope.
        if ($reportError) {
            try {
                Log::error('Error: '.$message.' with additional information: '.$errors);
            } catch (\Exception $exception) {
                // Don't do anything with non-static data here to absolutely ensure that the error
                // is logged.
                Log::error('Catastrophic error in ApiResponseTrait::failure. Unable to report error.');
            }
        }

        $response = [
            'success' => false,
            'message' => $message,
        ];
        if ($errors) {
            if (is_array($errors)) {
                $response['errors'] = $errors;
            } elseif (is_string($errors)) {
                $response['error'] = $errors;
            } else {
                $response['error'] = $errors;
            }
        }
        if ($flag) {
            $response['flag'] = $flag;
        }

        return response()->json($response, $code);
    }
}
