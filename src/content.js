import { cleanHtml, safeUrl } from "./security.js";
export const defaultHome = {
  hero_eyebrow: "BUILT WITH PURPOSE",
  hero_title: "Craftsmanship that frames the future.",
  hero_text:
    "Professional framing and construction built around quality, safety, communication and dependable execution.",
  about_title: "Working together. Building it right.",
  about_text:
    "We bring a hands-on approach to every project, coordinating crews, schedules and details with one goal: deliver strong work our clients can count on.",
  contact_title: "Have a project in mind?",
  contact_text:
    "Tell us about the scope, schedule and location. We’ll get back to you to discuss the next steps.",
};
export const defaultSettings = {
  site_name: "Galindos Builders LLC",
  header_logo_url: "",
  favicon_url: "",
  meta_image_url: "",
  phone: "",
  email: "",
  address: "",
  footer_text: "Professional framing & construction.",
};
export const defaultTabs = [
  { label: "Projects", url: "/projects" },
  { label: "Services", url: "/#services" },
  { label: "About", url: "/#about" },
  { label: "Contact", url: "/#contact" },
];
export const defaultServices = [
  {
    id: "wood",
    slug: "wood-framing",
    name: "Wood Framing",
    description:
      "Structural framing for residential and commercial construction.",
    visible: 1,
  },
  {
    id: "construction",
    slug: "construction",
    name: "Construction",
    description:
      "Reliable field execution with a focus on schedule, coordination and quality.",
    visible: 1,
  },
  {
    id: "support",
    slug: "project-support",
    name: "Project Support",
    description:
      "Experienced crews ready to support complex scopes and active jobsites.",
    visible: 1,
  },
];
export function urlOrEmpty(value) {
  try {
    return safeUrl(value);
  } catch {
    return "";
  }
}
export async function siteContent(db) {
  const [settings, projects, images, services, tabs, sections, areas, seo] =
    await Promise.all([
      db.query("SELECT * FROM site_settings"),
      db.query(
        "SELECT * FROM projects WHERE visible=1 ORDER BY sort_order,created_at DESC",
      ),
      db.query(
        "SELECT pi.*,m.alt_text AS media_alt FROM project_images pi LEFT JOIN media m ON m.id=pi.media_id ORDER BY pi.sort_order,pi.id",
      ),
      db.query("SELECT * FROM services WHERE visible=1 ORDER BY sort_order,id"),
      db.query("SELECT * FROM navigation_tabs ORDER BY sort_order,id"),
      db.query(
        "SELECT * FROM homepage_sections WHERE enabled=1 ORDER BY sort_order,section_key",
      ),
      db.query(
        "SELECT * FROM service_areas WHERE visible=1 ORDER BY sort_order,title",
      ),
      db.query("SELECT * FROM seo_settings"),
    ]);
  const stored = Object.fromEntries(
    settings.map((x) => [x.setting_key, x.setting_value]),
  );
  let home;
  try {
    home = JSON.parse(stored.home || "{}");
  } catch {
    home = {};
  }
  const brand = { ...defaultSettings, ...stored };
  for (const key of ["header_logo_url", "favicon_url", "meta_image_url"])
    brand[key] = urlOrEmpty(brand[key]);
  for (const p of projects) {
    p.images = images
      .filter((i) => i.project_id === p.id)
      .map((i) => ({ ...i, image_url: urlOrEmpty(i.image_url) }));
    p.featured_image =
      urlOrEmpty(p.featured_image) || p.images[0]?.image_url || "";
  }
  return {
    brand,
    home: { ...defaultHome, ...home },
    projects,
    services,
    tabs: tabs
      .filter((t) => t.visible !== 0)
      .map((t) => ({
        ...t,
        url: t.page_slug
          ? `/page/${encodeURIComponent(t.page_slug)}`
          : urlOrEmpty(legacyUrl(t.url || "/")),
      })),
    sections,
    areas,
    seo: Object.fromEntries(seo.map((x) => [x.page_key, x])),
    cleanHtml,
    urlOrEmpty,
  };
}
export function legacyUrl(url) {
  return url
    .replace(/\/projects\.php(?=$|[?#])/, "/projects")
    .replace(/\/index\.php(?=$|[?#])/, "/")
    .replace(/\/project(?:\.php)?\?job=([^&#]+)/, (_, s) => `/project/${s}`)
    .replace(/\/page(?:\.php)?\?slug=([^&#]+)/, (_, s) => `/page/${s}`);
}
export function metadata(site, cfg, route, item = {}) {
  const stored =
    site.seo[
      route === "/" ? "home" : route === "/projects" ? "projects" : route
    ] || {};
  const title =
    item.seo_title ||
    stored.title ||
    `${item.title || item.name || "Framing & Construction"} | ${site.brand.site_name}`;
  const description =
    item.seo_description ||
    stored.description ||
    cleanHtml(item.hero_text || item.description || site.home.hero_text)
      .replace(/<[^>]*>/g, "")
      .slice(0, 160);
  const canonical =
    urlOrEmpty(item.canonical_url || stored.canonical_url) ||
    `${cfg.siteUrl}${route}`;
  const absolute = (value) => (value ? new URL(value, cfg.siteUrl).href : "");
  return {
    title,
    description,
    keywords: stored.keywords || "",
    canonical: absolute(canonical),
    image: absolute(
      urlOrEmpty(
        item.og_image ||
          stored.og_image ||
          item.featured_image ||
          site.brand.meta_image_url,
      ),
    ),
    robots: stored.robots || item.robots || "index,follow",
    structure: JSON.stringify({
      "@context": "https://schema.org",
      "@type": route.startsWith("/services/")
        ? "Service"
        : route.startsWith("/project/")
          ? "CreativeWork"
          : "GeneralContractor",
      name: item.name || item.title || site.brand.site_name,
      url: absolute(canonical),
      description,
      ...(site.brand.phone ? { telephone: site.brand.phone } : {}),
      ...(site.brand.address ? { address: site.brand.address } : {}),
      ...(site.areas.length
        ? { areaServed: site.areas.map((a) => a.location || a.title) }
        : {}),
    }).replace(/</g, "\\u003c"),
  };
}
