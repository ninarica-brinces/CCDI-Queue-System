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

CREATE TABLE IF NOT EXISTS public.payment_histories (
    transaction_ref TEXT PRIMARY KEY,
    student_id TEXT NOT NULL,
    student_name TEXT NOT NULL,
    course_year TEXT,
    payment_type TEXT NOT NULL,
    cashier_staff TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'recorded',
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE public.payment_histories
    ADD COLUMN IF NOT EXISTS transaction_ref TEXT,
    ADD COLUMN IF NOT EXISTS student_id TEXT,
    ADD COLUMN IF NOT EXISTS student_name TEXT,
    ADD COLUMN IF NOT EXISTS course_year TEXT,
    ADD COLUMN IF NOT EXISTS payment_type TEXT,
    ADD COLUMN IF NOT EXISTS cashier_staff TEXT,
    ADD COLUMN IF NOT EXISTS status TEXT DEFAULT 'recorded',
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE public.payment_histories
    DROP CONSTRAINT IF EXISTS payment_histories_status_check;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'payment_histories'
          AND column_name = 'reference_number'
    ) THEN
        EXECUTE 'UPDATE public.payment_histories
                 SET transaction_ref = COALESCE(transaction_ref, reference_number::text)
                 WHERE transaction_ref IS NULL';
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'payment_histories'
          AND column_name = 'course'
    ) AND EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'payment_histories'
          AND column_name = 'year_level'
    ) THEN
        EXECUTE $sql$
            UPDATE public.payment_histories
            SET course_year = COALESCE(
                NULLIF(course_year, ''),
                NULLIF(concat_ws(' / ', NULLIF(course::text, ''), NULLIF(year_level::text, '')), '')
            )
            WHERE course_year IS NULL OR course_year = ''
        $sql$;
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'payment_histories'
          AND column_name = 'payment_description'
    ) THEN
        EXECUTE 'UPDATE public.payment_histories
                 SET payment_type = COALESCE(payment_type, payment_description::text)
                 WHERE payment_type IS NULL';
    ELSIF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'payment_histories'
          AND column_name = 'payment_method'
    ) THEN
        EXECUTE 'UPDATE public.payment_histories
                 SET payment_type = COALESCE(payment_type, payment_method::text)
                 WHERE payment_type IS NULL';
    END IF;
END
$$;

UPDATE public.payment_histories
SET transaction_ref = COALESCE(NULLIF(transaction_ref, ''), 'legacy-' || ctid::text),
    student_id = COALESCE(student_id, ''),
    student_name = COALESCE(student_name, ''),
    payment_type = COALESCE(NULLIF(payment_type, ''), 'Payment activity'),
    cashier_staff = COALESCE(NULLIF(cashier_staff, ''), 'Historical import'),
    status = COALESCE(NULLIF(status, ''), 'recorded'),
    created_at = COALESCE(created_at, CURRENT_TIMESTAMP);

ALTER TABLE public.payment_histories
    ALTER COLUMN transaction_ref SET NOT NULL,
    ALTER COLUMN student_id SET NOT NULL,
    ALTER COLUMN student_name SET NOT NULL,
    ALTER COLUMN payment_type SET NOT NULL,
    ALTER COLUMN cashier_staff SET NOT NULL,
    ALTER COLUMN status SET DEFAULT 'recorded',
    ALTER COLUMN status SET NOT NULL,
    ALTER COLUMN created_at SET DEFAULT CURRENT_TIMESTAMP,
    ALTER COLUMN created_at SET NOT NULL;

DO $$
BEGIN
    IF to_regclass('public.payment_activity_records') IS NOT NULL THEN
        INSERT INTO public.payment_histories (
            transaction_ref,
            student_id,
            student_name,
            course_year,
            payment_type,
            cashier_staff,
            status,
            created_at
        )
        SELECT reference_number,
               student_id,
               student_name,
               concat_ws(' / ', NULLIF(course, ''), NULLIF(year_level, '')),
               payment_description,
               cashier_staff,
               COALESCE(NULLIF(status, ''), 'recorded'),
               created_at
        FROM public.payment_activity_records
        WHERE reference_number IS NOT NULL
        ON CONFLICT DO NOTHING;
    END IF;
END
$$;

ALTER TABLE public.payment_histories
    DROP COLUMN IF EXISTS amount_paid;

CREATE INDEX IF NOT EXISTS payment_histories_created_at_idx
    ON public.payment_histories (created_at DESC);

CREATE INDEX IF NOT EXISTS payment_histories_student_id_idx
    ON public.payment_histories (student_id, created_at DESC);

ALTER TABLE public.queue_tickets
    DROP COLUMN IF EXISTS payment_percentage;
ALTER TABLE public.queue_tickets
    DROP COLUMN IF EXISTS discount;

COMMIT;
