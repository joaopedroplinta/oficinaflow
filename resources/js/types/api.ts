export type Data<T> = { data: T };

export type Page<T> = {
    data: T[];
    meta: { current_page: number; last_page: number; total: number };
};
