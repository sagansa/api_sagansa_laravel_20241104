<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApplicantDetail;
use Illuminate\Http\Request;

class RecruitmentController extends Controller
{
    /**
     * Ambil detail profil (data pribadi + rekening) milik user login.
     * Data berada di DB recruitment (mysql_recruitment) lewat relasi
     * ApplicantDetail milik User.
     */
    public function getDetails(Request $request)
    {
        $user = $request->user();
        $details = ApplicantDetail::where('user_id', $user->id)->first();

        return response()->json([
            'success' => true,
            'details' => $details,
            'locked' => $this->isLocked($details),
        ]);
    }

    /**
     * Update detail profil.
     *
     * Aturan kunci (lock):
     * - Bila status ∈ submitted/accepted/reviewed/rejected DAN join_date sudah
     *   terisi, user HANYA boleh mengubah field rekening:
     *   bank_account_name, bank_account_number, bank_name.
     * - Bila status sudah disubmit tapi join_date belum terisi, seluruh
     *   profil terkunci (tidak bisa ubah apa pun).
     * - Bank account boleh diubah kapanpun (sesuai kebijakan mobile).
     */
    public function updateDetails(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'nickname' => 'nullable|string|max:255',
            'is_experienced' => 'nullable|boolean',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'birth_place' => 'nullable|string|max:255',
            'birth_date' => 'nullable|date',
            'gender' => 'nullable|string|in:male,female,other',
            'nik' => 'nullable|string|max:20',
            'religion' => 'nullable|string|max:255',
            'marital_status' => 'nullable|string|max:255',
            'children_count' => 'nullable|integer',
            'education_level' => 'nullable|string|max:255',
            'education_major' => 'nullable|string|max:255',
            'father_name' => 'nullable|string|max:255',
            'mother_name' => 'nullable|string|max:255',
            'home_location' => 'nullable|string',
            'emergency_phone' => 'nullable|string|max:20',
            'emergency_name' => 'nullable|string|max:255',
            'driver_license' => 'nullable|string|max:255',
            'bank_account_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:255',
        ]);

        $details = ApplicantDetail::where('user_id', $user->id)->first();

        // Field rekening selalu boleh diubah kapanpun.
        $bankKeys = ['bank_account_name', 'bank_account_number', 'bank_name'];

        if ($details && in_array($details->status, ['submitted', 'accepted', 'reviewed', 'rejected'])) {
            if ($details->join_date) {
                foreach ($request->keys() as $key) {
                    if (!in_array($key, $bankKeys)
                        && $request->has($key)
                        && $request->input($key) != ($details->$key ?? null)
                    ) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Profil terkunci. Hanya data rekening yang dapat diubah.',
                        ], 403);
                    }
                }
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Profil sudah disubmit dan tidak dapat diubah.',
                ], 403);
            }
        }

        $data = $request->except(['ktp_image', 'selfie_image']);

        // Handle empty children_count
        if (isset($data['children_count']) && ($data['children_count'] === '' || $data['children_count'] === null)) {
            $data['children_count'] = 0;
        }

        $details = ApplicantDetail::updateOrCreate(
            ['user_id' => $user->id],
            $data
        );

        return response()->json([
            'success' => true,
            'message' => 'Profil berhasil diperbarui.',
            'details' => $details,
            'locked' => $this->isLocked($details),
        ]);
    }

    /**
     * Tentukan apakah profil terkunci (field non-rekening tidak bisa diubah).
     */
    private function isLocked(?ApplicantDetail $details): bool
    {
        if (!$details) {
            return false;
        }

        if (in_array($details->status, ['submitted', 'accepted', 'reviewed', 'rejected'])) {
            return (bool) $details->join_date;
        }

        return false;
    }
}
