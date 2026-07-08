import { Link } from '@inertiajs/react';
import {
    BarChart2,
    Bot,
    BookOpen,
    FolderGit2,
    History,
    LayoutGrid,
    NotebookPen,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard, pnl, tradingHistory, tradingJournal } from '@/routes';
import { settings as botSettings } from '@/routes/bot';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'PNL Calendar',
        href: pnl(),
        icon: BarChart2,
    },
    {
        title: 'Trading History',
        href: tradingHistory(),
        icon: History,
    },
    {
        title: 'Trading Journal',
        href: tradingJournal(),
        icon: NotebookPen,
    },
    {
        title: 'Bot',
        href: botSettings(),
        icon: Bot,
    },
];

const footerNavItems: NavItem[] = [];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
