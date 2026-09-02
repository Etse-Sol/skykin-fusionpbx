import type { Metadata } from "next";
import { Inter, Sora } from "next/font/google";
import Script from "next/script";
import { SiteHeader } from "@/components/site-header";
import { SiteFooter } from "@/components/site-footer";
import { ThemeSwitcher } from "@/components/theme-switcher";
import { site } from "@/lib/site";
import { DEFAULT_THEME, THEME_STORAGE_KEY } from "@/lib/themes";
import "./globals.css";

const themeScript = `try{var t=localStorage.getItem(${JSON.stringify(
  THEME_STORAGE_KEY,
)});if(t)document.documentElement.dataset.theme=t;}catch(e){}`;

const display = Sora({
  variable: "--font-display",
  subsets: ["latin"],
  weight: ["500", "600", "700"],
});

const body = Inter({
  variable: "--font-body",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  metadataBase: new URL(site.url),
  title: {
    default: `${site.name} — ${site.tagline}`,
    template: `%s — ${site.name}`,
  },
  description: site.description,
  openGraph: {
    title: `${site.name} — ${site.tagline}`,
    description: site.description,
    url: site.url,
    siteName: site.name,
    type: "website",
  },
  icons: {
    icon: [
      { url: "/favicon.png", sizes: "32x32", type: "image/png" },
      { url: "/favicon.ico", sizes: "48x48" },
    ],
    apple: "/images/favicon.png",
  },
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html
      lang="en"
      data-theme={DEFAULT_THEME}
      data-scroll-behavior="smooth"
      className={`${display.variable} ${body.variable} h-full antialiased`}
      suppressHydrationWarning
    >
      <body className="flex min-h-full flex-col bg-ink">
        <Script id="skykin-theme" strategy="beforeInteractive">
          {themeScript}
        </Script>
        <SiteHeader />
        <main className="flex-1">{children}</main>
        <SiteFooter />
        <ThemeSwitcher />
      </body>
    </html>
  );
}
