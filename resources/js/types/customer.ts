export type Customer = {
    id: number;
    name: string;
    phone: string;
    email: string | null;
    active: boolean;
    devices_count?: number;
};

export type Device = {
    id: number;
    customer_id: number;
    category: string;
    brand: string;
    model: string;
    serial_number: string | null;
    active: boolean;
};
