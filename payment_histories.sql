BEGIN;

CREATE TABLE IF NOT EXISTS public.payment_histories (
    reference_number TEXT PRIMARY KEY,
    student_id TEXT NOT NULL,
    student_name TEXT NOT NULL,
    course TEXT,
    year_level TEXT,
    amount_paid NUMERIC(12, 2) NOT NULL CHECK (amount_paid >= 0),
    payment_method TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cashier_staff TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('paid', 'pending'))
);

CREATE INDEX IF NOT EXISTS payment_histories_created_at_idx
    ON public.payment_histories (created_at DESC);

CREATE INDEX IF NOT EXISTS payment_histories_student_id_idx
    ON public.payment_histories (student_id, created_at DESC);

INSERT INTO public.payment_histories (
    reference_number,
    student_id,
    student_name,
    course,
    year_level,
    amount_paid,
    payment_method,
    created_at,
    cashier_staff,
    status
)
SELECT activity.reference_number,
       activity.student_id,
       COALESCE(ticket.student_name, ''),
       ticket.course,
       ticket.year_level::text,
       activity.amount_paid,
       COALESCE(ticket.payment_method, 'Unknown'),
       activity.created_at,
       'Historical import',
       CASE WHEN activity.amount_paid > 0 THEN 'paid' ELSE 'pending' END
FROM public.payment_activity_records AS activity
LEFT JOIN public.queue_tickets AS ticket
    ON ticket.ticket_number = activity.reference_number
ON CONFLICT (reference_number) DO NOTHING;

COMMIT;
