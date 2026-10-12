import { Badge } from '@/components/ui/badge';
import { roleBadgeClasses, roleLabels } from '@/lib/roles';
import { cn } from '@/lib/utils';
import type { RoleName } from '@/types';

export function RoleBadge({
    role,
    className,
}: {
    role: RoleName | null;
    className?: string;
}) {
    if (!role) {
        return null;
    }

    return (
        <Badge
            className={cn(
                'border-transparent text-base',
                roleBadgeClasses[role],
                className,
            )}
            data-test="role-badge"
        >
            {roleLabels[role]}
        </Badge>
    );
}
