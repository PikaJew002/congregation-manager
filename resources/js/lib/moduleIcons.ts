import { LayoutGrid, Mail, Music, Video } from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';

const icons: Record<string, LucideIcon> = {
    mail: Mail,
    video: Video,
    music: Music,
};

/** The Lucide icon for a module's icon name, as the sidebar shows it. */
export function moduleIcon(name: string): LucideIcon {
    return icons[name] ?? LayoutGrid;
}
