<?php

namespace App\Domain\Devices\Support;

/**
 * Spec section 6: "არსებული BioStar-ის მიგრაცია: ჯერ მხოლოდ read-only
 * inventory/export და card mapping, შემდეგ ერთ სატესტო reader-ზე პილოტი.
 * ერთსა და იმავე მონაცემზე ორი დამოუკიდებელი writer არ ჩართო; განსაზღვრე
 * system of record და rollback. ცოცხალ ობიექტზე cutover-ს ჰქონდეს
 * შეთანხმებული ფანჯარა."
 *
 * This class is the DOCUMENTED PLAN only — spec section 2 explicitly lists
 * "არსებული BioStar" as unknown ("ჯერჯერობით უცნობია"), so nothing here is
 * executed against a real BioStar instance: there is no BioStar
 * credentials config, no import job scheduled, and no code path that
 * writes to BioStar. `App\Http\Controllers\Devices\BioStarMigrationPlanController`
 * renders this read-only, for the operations team to review and confirm
 * before any real migration work starts.
 */
final class BioStarMigrationPlan
{
    /**
     * @return list<array{
     *   phase: int,
     *   title: string,
     *   description: string,
     *   system_of_record: string,
     *   status: string,
     * }>
     */
    public static function phases(): array
    {
        return [
            [
                'phase' => 1,
                'title' => 'Read-only ინვენტარი და card mapping',
                'description' => 'BioStar-იდან ექსპორტდება მხოლოდ წაკითხვის რეჟიმში: '.
                    'მოწყობილობების სია, თანამშრომელთა/ბარათების არსებული მიბმები. '.
                    'ODA-ში ეს ჩანაწერები იტვირთება როგორც წინადადება (candidate) და '.
                    'არა როგორც უკვე გააქტიურებული credential — ODA-ს ოპერატორმა უნდა '.
                    'დაადასტუროს თითოეული მიბმა ხელით App\\Domain\\Devices\\Actions\\IssueCredentialAction-ის '.
                    'გავლით, სანამ ის აქტიური გახდება. BioStar ამ ფაზაში რჩება ერთადერთ '.
                    'system of record-ად ცოცხალი წვდომისთვის — ODA მას არაფერს არ წერს.',
                'system_of_record' => 'BioStar (უცვლელი)',
                'status' => 'დაგეგმილი — არ დაწყებულა',
            ],
            [
                'phase' => 2,
                'title' => 'ერთი სატესტო reader — პილოტი',
                'description' => 'ერთი, არაკრიტიკული reader გადაერთვება ODA-ს მართვაზე '.
                    '(App\\Domain\\Devices\\Adapters\\SupremaGSdkAdapter რეალურ G-SDK '.
                    'Gateway-თან, ამ ეტაპისთვის ჯერ დაუდასტურებელი). BioStar-ში ამ '.
                    'კონკრეტული reader-ის ჩანაწერები freeze-დება (მხოლოდ წაკითხვადი), '.
                    'რომ ორი დამოუკიდებელი writer არასდროს არ ეხებოდეს ერთსა და იმავე '.
                    'reader-ს ერთდროულად. Rollback: ODA-ს sync command queue ჩერდება, '.
                    'reader უბრუნდება BioStar-ის მართვას, ODA-ში ამ reader-ის ჩანაწერები '.
                    'რჩება ისტორიად და არ იშლება.',
                'system_of_record' => 'BioStar → ODA (მხოლოდ ამ ერთი reader-ისთვის)',
                'status' => 'დაგეგმილი — არ დაწყებულა',
            ],
            [
                'phase' => 3,
                'title' => 'სრული cutover',
                'description' => 'დარჩენილი reader-ები გადაერთვება ODA-ზე შეთანხმებულ '.
                    'window-ში (spec: "ცოცხალ ობიექტზე cutover-ს ჰქონდეს შეთანხმებული '.
                    'ფანჯარა"). BioStar გადადის მხოლოდ არქივის/read-only რეჟიმში. '.
                    'ეს ფაზა მოითხოვს: (ა) რეალურ hardware-ზე ვალიდირებულ '.
                    'SupremaGSdkAdapter-ს, (ბ) დადასტურებულ card enrollment რეჟიმს '.
                    '(EM/MIFARE), (გ) ბიზნესის მიერ დამტკიცებულ rollback ფანჯარას.',
                'system_of_record' => 'ODA (ექსკლუზიურად)',
                'status' => 'დაგეგმილი — არ დაწყებულა, დამოკიდებულია ფაზა 1-2-ის შედეგზე',
            ],
        ];
    }
}
