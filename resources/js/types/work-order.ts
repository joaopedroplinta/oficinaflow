import type { Customer } from './customer';

export type WorkOrder = {
    id: number;
    number: number;
    status: string;
    status_label: string;
    customer: Customer;
    device_id: number;
    device: { category: string; brand: string; model: string; serial_number: string | null };
    reported_problem: string;
    intake_condition: string;
    accessories: string;
    received_at: string;
};
