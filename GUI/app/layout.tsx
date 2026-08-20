import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "Тренер собеседований",
  description: "Тренируйте ответы на технические вопросы и отслеживайте прогресс.",
  other: { "codex-preview": "development" },
  icons: { icon: "/favicon.svg", shortcut: "/favicon.svg" },
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <html lang="ru"><body>{children}</body></html>;
}
