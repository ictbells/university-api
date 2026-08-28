<?php

namespace App\Support;

class AdmissionGuideContent
{
    /**
     * @return array{title: string, intro: string, sections: list<array{heading: string, body: string}>}
     */
    public static function sample(): array
    {
        return [
            'title' => 'Undergraduate Admission Guide — 2026/2027',
            'intro' => 'This guide explains how to apply for admission into Bells University of Technology through the student portal. Read it before you create an account, pay the application fee, or complete the form.',
            'sections' => [
                [
                    'heading' => 'Who can apply',
                    'body' => "UTME: Candidates who sat the current JAMB UTME and chose Bells University, or are ready to do so, with the required O'level credits.\n\nDirect Entry: Holders of A-Level, JUPEB, NCE, ND, or equivalent qualifications seeking 200-level entry.\n\nJUPEB: Candidates applying for the foundation programme at the University's JUPEB centre.\n\nTransfer: Students currently enrolled in another recognised university who wish to continue at Bells.\n\nPostgraduate: Applicants for taught or research postgraduate programmes should use the postgraduate category.",
                ],
                [
                    'heading' => 'How to apply on this portal',
                    'body' => "1. Create an account with your NIN. The names on your NIN become your official record.\n2. Sign in and choose an open application session and admission category (UTME, Direct Entry, JUPEB, Transfer, or Postgraduate).\n3. Pay the application fee. The form stays locked until the fee is paid.\n4. Complete every section of the application form: biodata, health, next of kin, sponsor, qualifications, programme choices, and required documents.\n5. Submit the form. After submission, Admissions reviews your file. Track progress from Status.",
                ],
                [
                    'heading' => 'Documents you will need',
                    'body' => "Have clear scans ready before you start:\n• Passport photograph (recent, plain background)\n• O'level result(s) — WAEC, NECO, or NABTEB\n• JAMB result slip (UTME applicants)\n• Birth certificate or age declaration\n• Direct Entry / JUPEB / transfer credentials where they apply\n\nUploads must match the names on your NIN. Blurry or incomplete files delay screening.",
                ],
                [
                    'heading' => 'Fees and payment',
                    'body' => "Pay the application fee from the portal after you select an intake. Use the listed payment channels only.\n\nIf you are offered admission, an acceptance fee invoice is raised. Pay it to accept the offer, then complete physical clearance as directed by Admissions.\n\nTuition and other charges are billed after matriculation. Keep receipts from Transactions.",
                ],
                [
                    'heading' => 'After you submit',
                    'body' => "Admissions screens, verifies, and (where required) shortlists your file. You will see stage updates on Status and in Notifications.\n\nIf an offer is issued, open the admission letter from the portal, pay the acceptance fee, and attend physical clearance with the originals of the documents you uploaded.\n\nHostel selection opens by level after you have paid the required share of current-session tuition.",
                ],
                [
                    'heading' => 'Need help?',
                    'body' => "Admissions Office, Bells University of Technology, Ota.\nEmail: admissions@bellsuniversity.edu.ng\nUse the contact details shown on the sign-in page if they have been updated for this session.\n\nDo not send payments or documents outside this portal.",
                ],
            ],
        ];
    }
}
