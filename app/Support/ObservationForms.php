<?php

namespace App\Support;

use App\Models\Observation;
use Illuminate\Validation\Rule;

/**
 * The original INM observation forms, as the hospital's observation sheets
 * define them.
 *
 * These definitions are the starting point only: DefaultIndicatorForms turns
 * them into published form version 1, and from then on forms are edited in
 * the form builder. They are still used to keep the original observation
 * columns ("column" storage) and `data` JSON ("data" storage) readable and
 * up to date for these four forms.
 *
 * No compliance formula is defined here: the PRD does not specify one yet.
 */
class ObservationForms
{
    public const IDENTIFICATION = 'identification';

    public const PPE = 'ppe';

    public const FALL_PREVENTION = 'fall_prevention';

    public const SPECIALIST_VISIT = 'specialist_visit';

    /**
     * Observation columns that hold form answers directly.
     */
    public const COLUMNS = [
        'officer_name',
        'patient_name',
        'medication_compliant',
        'procedure_compliant',
        'patient_name_verified',
        'birth_date_verified',
        'notes',
    ];

    /**
     * INM indicator names mapped to their form type.
     */
    public const INM_INDICATORS = [
        'Kepatuhan Identifikasi Pasien' => self::IDENTIFICATION,
        'Kepatuhan Penggunaan Alat Pelindung Diri' => self::PPE,
        'Kepatuhan Upaya Pencegahan Risiko Pasien Jatuh' => self::FALL_PREVENTION,
        'Kepatuhan Visite Dokter Spesialis' => self::SPECIALIST_VISIT,
    ];

    /**
     * @return array<string, array{sections: list<array{title: ?string, fields: array<string, array<string, mixed>>}>}>
     */
    public static function definitions(): array
    {
        return [
            self::IDENTIFICATION => [
                'sections' => [
                    [
                        'title' => null,
                        'fields' => [
                            'officer_name' => ['label' => 'Nama Petugas yang Diobservasi', 'type' => 'text', 'storage' => 'column'],
                            'patient_name' => ['label' => 'Nama Pasien', 'type' => 'text', 'storage' => 'column'],
                        ],
                    ],
                    [
                        'title' => 'Aktivitas yang Diobservasi',
                        'fields' => [
                            'medication_compliant' => ['label' => 'Sebelum pemberian obat atau injeksi', 'type' => 'boolean', 'storage' => 'column'],
                            'procedure_compliant' => ['label' => 'Sebelum tindakan atau prosedur', 'type' => 'boolean', 'storage' => 'column'],
                        ],
                    ],
                    [
                        'title' => 'Kriteria Identifikasi Pasien',
                        'fields' => [
                            'patient_name_verified' => ['label' => 'Nama pasien', 'type' => 'boolean', 'storage' => 'column'],
                            'birth_date_verified' => ['label' => 'Tanggal lahir', 'type' => 'boolean', 'storage' => 'column'],
                        ],
                    ],
                    [
                        'title' => null,
                        'fields' => [
                            'notes' => ['label' => 'Catatan', 'type' => 'textarea', 'storage' => 'column', 'required' => false],
                        ],
                    ],
                ],
            ],

            self::PPE => [
                'sections' => [
                    [
                        'title' => null,
                        'fields' => [
                            'officer_name' => ['label' => 'Nama Petugas', 'type' => 'text', 'storage' => 'column'],
                            'procedure' => ['label' => 'Tindakan', 'type' => 'text', 'storage' => 'data'],
                        ],
                    ],
                    [
                        'title' => 'Jenis APD yang Digunakan',
                        'fields' => [
                            'mask' => ['label' => 'Masker', 'type' => 'boolean', 'storage' => 'data'],
                            'gloves' => ['label' => 'Handscoon', 'type' => 'boolean', 'storage' => 'data'],
                            'gown' => ['label' => 'Gown/Apron', 'type' => 'boolean', 'storage' => 'data'],
                            'cap' => ['label' => 'Nursecap/Topi Medis', 'type' => 'boolean', 'storage' => 'data'],
                            'closed_footwear' => ['label' => 'Alas Kaki Tertutup', 'type' => 'boolean', 'storage' => 'data'],
                        ],
                    ],
                    [
                        'title' => null,
                        'fields' => [
                            'notes' => ['label' => 'Keterangan', 'type' => 'textarea', 'storage' => 'column', 'required' => false],
                        ],
                    ],
                ],
            ],

            self::FALL_PREVENTION => [
                'sections' => [
                    [
                        'title' => null,
                        'fields' => [
                            'officer_name' => ['label' => 'Nama Petugas', 'type' => 'text', 'storage' => 'column'],
                            'patient_name' => ['label' => 'Nama Pasien', 'type' => 'text', 'storage' => 'column'],
                            'medical_record_number' => [
                                'label' => 'No. RM',
                                'type' => 'text',
                                'storage' => 'data',
                                'max' => 30,
                                'rules' => ['regex:/^[A-Za-z0-9.\-\/]+$/'],
                            ],
                        ],
                    ],
                    [
                        'title' => 'Upaya Pencegahan Risiko Jatuh',
                        'fields' => [
                            'initial_screening' => ['label' => 'Screening Awal', 'type' => 'boolean', 'storage' => 'data'],
                            'follow_up_screening' => ['label' => 'Screening Ulang', 'type' => 'boolean', 'storage' => 'data'],
                            'education' => ['label' => 'Edukasi', 'type' => 'boolean', 'storage' => 'data'],
                        ],
                    ],
                    [
                        'title' => null,
                        'fields' => [
                            'notes' => ['label' => 'Keterangan', 'type' => 'textarea', 'storage' => 'column', 'required' => false],
                        ],
                    ],
                ],
            ],

            self::SPECIALIST_VISIT => [
                'sections' => [
                    [
                        'title' => null,
                        'fields' => [
                            'doctor_name' => ['label' => 'Nama Dokter Spesialis', 'type' => 'text', 'storage' => 'data'],
                            'patients_treated' => ['label' => 'Jumlah Pasien yang Dirawat', 'type' => 'number', 'storage' => 'data'],
                            'patients_visited_on_time' => [
                                'label' => 'Jumlah Pasien yang Divisite Dokter (06.00–14.00)',
                                'type' => 'number',
                                'storage' => 'data',
                                'rules' => ['lte:patients_treated'],
                            ],
                            'late_visit_reason' => ['label' => 'Alasan Ketidaktepatan Waktu Visite', 'type' => 'textarea', 'storage' => 'data', 'required' => false],
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function has(?string $formType): bool
    {
        return $formType !== null && array_key_exists($formType, self::definitions());
    }

    /**
     * @return list<array{title: ?string, fields: array<string, array<string, mixed>>}>
     */
    public static function sections(string $formType): array
    {
        return self::definitions()[$formType]['sections'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function fields(string $formType): array
    {
        return array_merge(...array_column(self::sections($formType), 'fields'));
    }

    /**
     * Read a field's stored value from an observation.
     */
    public static function value(Observation $observation, string $key, array $field): mixed
    {
        return $field['storage'] === 'column'
            ? $observation->getAttribute($key)
            : ($observation->data[$key] ?? null);
    }
}
