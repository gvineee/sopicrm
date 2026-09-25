/**
 * Audit A18: „შერეული ქართული/ინგლისური … ზოგან სრული ISO timestamp ჩანს."
 *
 * The convention was already right — roughly twenty screens map a status code
 * to a Georgian label before rendering it. The problem was that about as many
 * screens did not, so the same person saw „მიმდინარეობს" on one page and
 * `data_gap`, `calculated` or a raw `2026-09-21T14:03:00+04:00` on the next.
 *
 * Every map lives here so a status has ONE Georgian name across the product,
 * and so adding a value to an enum has one obvious place to be named. Each
 * lookup falls back to the raw code rather than to an empty string: an
 * unnamed new status should look untranslated, not look like missing data.
 */

function lookup(map: Record<string, string>, value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';

    return map[value] ?? value;
}

const TIMESHEET_STATUS: Record<string, string> = {
    draft: 'შავი ვარიანტი',
    submitted: 'გაგზავნილი',
    approved: 'დამტკიცებული',
    locked: 'ჩაკეტილი',
    rejected: 'უარყოფილი',
};

const PAY_RUN_STATUS: Record<string, string> = {
    draft: 'შავი ვარიანტი',
    calculated: 'გამოთვლილი',
    reviewed: 'გადამოწმებული',
    approved: 'დამტკიცებული',
    locked: 'ჩაკეტილი',
};

const ADVANCE_STATUS: Record<string, string> = {
    outstanding: 'დაუფარავი',
    fully_deducted: 'სრულად დაქვითული',
    cancelled: 'გაუქმებული',
};

const EMAIL_DELIVERY_STATUS: Record<string, string> = {
    queued: 'რიგშია',
    sent: 'გაგზავნილია',
    failed: 'ვერ გაიგზავნა',
    pending: 'მოლოდინში',
    processing: 'მუშავდება',
    completed: 'დასრულებული',
    cancelled: 'გაუქმებული',
};

const RATE_TYPE: Record<string, string> = {
    hourly: 'საათობრივი',
    daily: 'დღიური',
};

const ATTENDANCE_SESSION_STATUS: Record<string, string> = {
    open: 'ღია',
    closed: 'დახურული',
    superseded: 'ჩანაცვლებული',
};

/**
 * These are what an operator is asked to act on, so the label has to say what
 * happened rather than name a code. „unknown_out" in particular is the case
 * spec 02 §7.6 cares about: the exit is not missing, it is undetermined.
 */
const ATTENDANCE_ANOMALY: Record<string, string> = {
    duplicate_in: 'გამეორებული შესვლა',
    unknown_out: 'გასვლა დაუდგენელია',
    missing_out: 'გასვლა არ დაფიქსირდა',
    excessive_duration: 'არარეალურად ხანგრძლივი სესია',
    impossible_site_crossing: 'შეუძლებელი გადაადგილება ობიექტებს შორის',
    late_arriving_data: 'დაგვიანებით მიღებული მონაცემი',
    clock_drift: 'მოწყობილობის საათის გადახრა',
    out_of_order_events: 'მოვლენები არეული თანმიმდევრობით',
    data_gap: 'მონაცემის გამოტოვება',
    undirected_reader: 'წამკითხველს მიმართულება არ აქვს მითითებული',
};

const ADJUSTMENT_STATUS: Record<string, string> = {
    pending: 'განსახილველი',
    approved: 'დამტკიცებული',
    rejected: 'უარყოფილი',
};

const SUBMISSION_STATUS: Record<string, string> = {
    pending_review: 'განხილვაშია',
    submitted: 'გაგზავნილი',
    accepted: 'მიღებული',
    returned: 'დაბრუნებული',
};

const ATTACHMENT_CLASSIFICATION: Record<string, string> = {
    before: 'სამუშაომდე',
    after: 'სამუშაოს შემდეგ',
    other: 'სხვა',
};

const ATTACHMENT_STATUS: Record<string, string> = {
    uploaded: 'იტვირთება',
    available: 'ხელმისაწვდომი',
    failed: 'ატვირთვა ჩაიშალა',
    quarantined: 'კარანტინში',
};

const EMPLOYEE_STATUS: Record<string, string> = {
    active: 'აქტიური',
    inactive: 'არააქტიური',
    terminated: 'დათხოვნილი',
};

const ASSET_TRACKING_TYPE: Record<string, string> = {
    individual: 'ინდივიდუალური',
    kit_component: 'ნაკრების ნაწილი',
    quantity: 'რაოდენობრივი',
    consumable: 'ხარჯვადი',
};

const DEVICE_SYNC_STATUS: Record<string, string> = {
    in_sync: 'სინქრონიზებულია',
    pending: 'სინქრონიზაციის მოლოდინში',
    error: 'შეცდომა',
};

const DEVICE_COMMAND_TYPE: Record<string, string> = {
    add_user: 'მომხმარებლის დამატება',
    update_user: 'მომხმარებლის განახლება',
    revoke_credential: 'ბარათის გაუქმება',
    sync_access_group: 'დაშვების ჯგუფის სინქრონიზაცია',
    sync_schedule: 'განრიგის სინქრონიზაცია',
};

const READER_DIRECTION: Record<string, string> = {
    in: 'შესვლა',
    out: 'გასვლა',
    unspecified: 'მიუთითებელი',
    entry: 'შესვლა',
    exit: 'გასვლა',
};

const WBS_LEVEL_TYPE: Record<string, string> = {
    site: 'ობიექტი',
    building: 'კორპუსი',
    zone: 'ზონა',
    floor: 'სართული',
    room: 'ოთახი',
    axis: 'ღერძი',
};

/**
 * The eleven business roles (database/seeders/RbacBaseSeeder.php ROLES). Their
 * Georgian names existed only as a PHP docblock comment, so every screen that
 * showed a role showed the slug.
 */
const USER_ROLE: Record<string, string> = {
    owner: 'მფლობელი/დირექტორი',
    system_admin: 'სისტემური ადმინისტრატორი',
    hr: 'HR',
    finance: 'ფინანსისტი',
    project_manager: 'პროექტის მენეჯერი',
    foreman: 'ბრიგადირი',
    warehouse_keeper: 'საწყობის პასუხისმგებელი',
    employee: 'თანამშრომელი',
    procurement_manager: 'შესყიდვების მენეჯერი',
    qa_safety: 'ხარისხის/უსაფრთხოების სპეციალისტი',
    client_subcontractor: 'კლიენტი/ქვეკონტრაქტორი',
};

/** The role a person holds on one project, as opposed to their system role. */
const PROJECT_ROLE: Record<string, string> = {
    manager: 'პროექტის მენეჯერი',
    member: 'წევრი',
    foreman: 'ბრიგადირი',
    supervisor: 'ზედამხედველი',
    observer: 'დამკვირვებელი',
    client: 'კლიენტის წარმომადგენელი',
};

export const PROJECT_ROLE_OPTIONS = Object.entries(PROJECT_ROLE).map(([value, label]) => ({ value, label }));

export const timesheetStatusLabel = (v?: string | null) => lookup(TIMESHEET_STATUS, v);
export const payRunStatusLabel = (v?: string | null) => lookup(PAY_RUN_STATUS, v);
export const advanceStatusLabel = (v?: string | null) => lookup(ADVANCE_STATUS, v);
export const emailDeliveryStatusLabel = (v?: string | null) => lookup(EMAIL_DELIVERY_STATUS, v);
export const rateTypeLabel = (v?: string | null) => lookup(RATE_TYPE, v);
export const attendanceSessionStatusLabel = (v?: string | null) => lookup(ATTENDANCE_SESSION_STATUS, v);
export const attendanceAnomalyLabel = (v?: string | null) => lookup(ATTENDANCE_ANOMALY, v);
export const adjustmentStatusLabel = (v?: string | null) => lookup(ADJUSTMENT_STATUS, v);
export const submissionStatusLabel = (v?: string | null) => lookup(SUBMISSION_STATUS, v);
export const attachmentClassificationLabel = (v?: string | null) => lookup(ATTACHMENT_CLASSIFICATION, v);
export const attachmentStatusLabel = (v?: string | null) => lookup(ATTACHMENT_STATUS, v);
export const employeeStatusLabel = (v?: string | null) => lookup(EMPLOYEE_STATUS, v);
export const assetTrackingTypeLabel = (v?: string | null) => lookup(ASSET_TRACKING_TYPE, v);
export const deviceSyncStatusLabel = (v?: string | null) => lookup(DEVICE_SYNC_STATUS, v);
export const deviceCommandTypeLabel = (v?: string | null) => lookup(DEVICE_COMMAND_TYPE, v);
export const readerDirectionLabel = (v?: string | null) => lookup(READER_DIRECTION, v);
export const wbsLevelTypeLabel = (v?: string | null) => lookup(WBS_LEVEL_TYPE, v);
export const userRoleLabel = (v?: string | null) => lookup(USER_ROLE, v);
export const projectRoleLabel = (v?: string | null) => lookup(PROJECT_ROLE, v);

/**
 * The backend serialises timestamps with `toIso8601String()`. Several screens
 * printed that string straight to the operator, so a device's last heartbeat
 * read `2026-09-21T14:03:00+04:00`. These render it in Georgian, and return a
 * caller-chosen placeholder when there is nothing — „—" by default, because a
 * missing value must not look like a zero time.
 */
export function formatDateTime(value?: string | null, fallback = '—'): string {
    if (!value) return fallback;

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;

    return date.toLocaleString('ka-GE', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function formatDate(value?: string | null, fallback = '—'): string {
    if (!value) return fallback;

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;

    return date.toLocaleDateString('ka-GE', { year: 'numeric', month: 'short', day: 'numeric' });
}
