import { Alert } from './ui';

export function SavedNotice({ message }: { message: string }) {
    return <Alert tone="success">{message}</Alert>;
}
