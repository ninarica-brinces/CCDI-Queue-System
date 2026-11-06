BEGIN;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'queue_tickets'
          AND column_name = 'payment_method'
    ) AND NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'queue_tickets'
          AND column_name = 'payment_description'
    ) THEN
        ALTER TABLE public.queue_tickets
            RENAME COLUMN payment_method TO payment_description;
    ELSIF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'queue_tickets'
          AND column_name = 'payment_method'
    ) THEN
        UPDATE public.queue_tickets
        SET payment_description = COALESCE(
            NULLIF(btrim(payment_description), ''),
            payment_method
        );
        ALTER TABLE public.queue_tickets
            DROP COLUMN payment_method;
    END IF;
END
$$;

CREATE TABLE IF NOT EXISTS public.payment_activity_records (
    reference_number TEXT PRIMARY KEY,
    student_id TEXT NOT NULL,
    student_name TEXT NOT NULL,
    course TEXT,
    year_level TEXT,
    payment_description TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cashier_staff TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'recorded'
);

ALTER TABLE public.payment_activity_records
    ADD COLUMN IF NOT EXISTS student_name TEXT;
ALTER TABLE public.payment_activity_records
    ADD COLUMN IF NOT EXISTS course TEXT;
ALTER TABLE public.payment_activity_records
    ADD COLUMN IF NOT EXISTS year_level TEXT;
ALTER TABLE public.payment_activity_records
    ADD COLUMN IF NOT EXISTS payment_description TEXT;
ALTER TABLE public.payment_activity_records
    ADD COLUMN IF NOT EXISTS cashier_staff TEXT;
ALTER TABLE public.payment_activity_records
    ADD COLUMN IF NOT EXISTS status TEXT DEFAULT 'recorded';

UPDATE public.payment_activity_records AS activity
SET student_name = COALESCE(NULLIF(activity.student_name, ''), ticket.student_name),
    course = COALESCE(activity.course, ticket.course),
    year_level = COALESCE(activity.year_level, ticket.year_level::text),
    payment_description = COALESCE(
        NULLIF(btrim(activity.payment_description), ''),
        NULLIF(
            concat_ws(
                ' / ',
                NULLIF(btrim(ticket.payment_description), ''),
                NULLIF(btrim(ticket.payment_period), '')
            ),
            ''
        ),
        'Payment activity'
    ),
    cashier_staff = COALESCE(NULLIF(activity.cashier_staff, ''), 'Historical import'),
    status = 'recorded'
FROM public.queue_tickets AS ticket
WHERE ticket.ticket_number = activity.reference_number;

UPDATE public.payment_activity_records
SET student_name = COALESCE(student_name, ''),
    payment_description = COALESCE(NULLIF(btrim(payment_description), ''), 'Payment activity'),
    cashier_staff = COALESCE(NULLIF(cashier_staff, ''), 'Historical import'),
    status = 'recorded';

ALTER TABLE public.payment_activity_records
    ALTER COLUMN student_name SET NOT NULL;
ALTER TABLE public.payment_activity_records
    ALTER COLUMN payment_description SET NOT NULL;
ALTER TABLE public.payment_activity_records
    ALTER COLUMN cashier_staff SET NOT NULL;
ALTER TABLE public.payment_activity_records
    ALTER COLUMN status SET DEFAULT 'recorded';
ALTER TABLE public.payment_activity_records
    DROP COLUMN IF EXISTS amount_paid;

DO $$
BEGIN
    IF to_regclass('public.payment_histories') IS NOT NULL THEN
        ALTER TABLE public.payment_histories
            DROP CONSTRAINT IF EXISTS payment_histories_status_check;
        ALTER TABLE public.payment_histories
            ADD COLUMN IF NOT EXISTS payment_description TEXT;

        UPDATE public.payment_histories AS activity
        SET payment_description = COALESCE(
            NULLIF(
                concat_ws(
                    ' / ',
                    NULLIF(btrim(ticket.payment_description), ''),
                    NULLIF(btrim(ticket.payment_period), '')
                ),
                ''
            ),
            'Payment activity'
        )
        FROM public.queue_tickets AS ticket
        WHERE ticket.ticket_number = activity.reference_number;

        UPDATE public.payment_histories
        SET payment_description = COALESCE(
                NULLIF(btrim(payment_description), ''),
                'Payment activity'
            ),
            status = 'recorded';

        ALTER TABLE public.payment_histories
            DROP COLUMN IF EXISTS amount_paid;
        ALTER TABLE public.payment_histories
            DROP COLUMN IF EXISTS payment_method;

        INSERT INTO public.payment_activity_records AS current_record (
            reference_number,
            student_id,
            student_name,
            course,
            year_level,
            payment_description,
            created_at,
            cashier_staff,
            status
        )
        SELECT reference_number,
               student_id,
               student_name,
               course,
               year_level,
               payment_description,
               created_at,
               cashier_staff,
               'recorded'
        FROM public.payment_histories
        ON CONFLICT (reference_number) DO UPDATE
        SET student_id = EXCLUDED.student_id,
            student_name = CASE
                WHEN current_record.student_name = ''
                    THEN EXCLUDED.student_name
                ELSE current_record.student_name
            END,
            course = COALESCE(current_record.course, EXCLUDED.course),
            year_level = COALESCE(current_record.year_level, EXCLUDED.year_level),
            payment_description = CASE
                WHEN current_record.payment_description = 'Payment activity'
                    THEN EXCLUDED.payment_description
                ELSE current_record.payment_description
            END,
            cashier_staff = CASE
                WHEN current_record.cashier_staff = 'Historical import'
                    THEN EXCLUDED.cashier_staff
                ELSE current_record.cashier_staff
            END,
            status = 'recorded';

        DROP TABLE public.payment_histories;
    END IF;
END
$$;

CREATE INDEX IF NOT EXISTS payment_activity_records_created_at_idx
    ON public.payment_activity_records (created_at DESC);

CREATE INDEX IF NOT EXISTS payment_activity_records_student_id_idx
    ON public.payment_activity_records (student_id, created_at DESC);

ALTER TABLE public.queue_tickets
    DROP COLUMN IF EXISTS payment_percentage;
ALTER TABLE public.queue_tickets
    DROP COLUMN IF EXISTS discount;

COMMIT;
