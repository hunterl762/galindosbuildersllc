import { z } from "zod";
import { cleanHtml, safeUrl } from "./security.js";
const field = (
  label,
  type = "text",
  max = 255,
  required = false,
  options = [],
) => ({ label, type, max, required, options });
const seo = {
  seo_title: field("SEO title"),
  seo_description: field("SEO description", "textarea", 2000),
  canonical_url: field("Canonical URL", "url", 700),
  og_image: field("Social image URL", "url", 700),
  robots: field("Search visibility", "select", 40, false, [
    "index,follow",
    "noindex,follow",
    "noindex,nofollow",
  ]),
};
const page = {
  slug: field("URL slug", "slug", 160, true),
  title: field("Page title", "text", 255, true),
  hero_text: field("Hero text", "textarea", 5000),
  content: field("Page content (basic HTML)", "html", 100000),
  featured_image: field("Featured image URL", "url", 700),
  visible: field("Published", "checkbox"),
  sort_order: field("Order", "number"),
  ...seo,
};
export const resources = {
  projects: {
    table: "projects",
    label: "Projects",
    permission: "content",
    fields: {
      slug: field("URL slug", "slug", 190, true),
      name: field("Project name", "text", 255, true),
      location: field("Location"),
      category: field("Category", "text", 120),
      status: field("Project status", "text", 100),
      description: field("Description", "textarea", 100000),
      completion_date: field("Completion date", "date"),
      client: field("Client"),
      contractor: field("General contractor"),
      scope: field("Scope of work", "textarea", 20000),
      square_footage: field("Square footage", "number"),
      project_type: field("Project type", "text", 160),
      service_id: field("Related service", "service", 100),
      featured_image: field("Featured image URL", "url", 700),
      stats: field("Project stats", "textarea", 10000),
      featured: field("Featured", "checkbox"),
      visible: field("Published", "checkbox"),
      sort_order: field("Order", "number"),
      ...seo,
    },
  },
  pages: {
    table: "pages",
    label: "Custom Pages",
    permission: "content",
    fields: {
      ...page,
      eyebrow: field("Hero eyebrow"),
      cta_title: field("CTA title"),
      cta_text: field("CTA text", "textarea", 5000),
      cta_label: field("CTA button label", "text", 120),
      cta_url: field("CTA URL", "url", 500),
    },
  },
  services: {
    table: "services",
    label: "Services",
    permission: "content",
    fields: {
      slug: page.slug,
      name: field("Service name", "text", 255, true),
      description: field("Summary", "textarea", 10000),
      hero_text: page.hero_text,
      content: page.content,
      featured_image: page.featured_image,
      visible: page.visible,
      sort_order: page.sort_order,
      ...seo,
    },
  },
  "service-areas": {
    table: "service_areas",
    label: "Service Areas",
    permission: "content",
    fields: { ...page, location: field("Location") },
  },
  navigation: {
    table: "navigation_tabs",
    label: "Navigation",
    permission: "content",
    fields: {
      label: field("Link label", "text", 120, true),
      url: field("Link URL", "url", 500),
      page_slug: field("Custom page slug", "text", 160),
      visible: page.visible,
      sort_order: page.sort_order,
    },
  },
  seo: {
    table: "seo_settings",
    id: "page_key",
    label: "SEO",
    permission: "content",
    fields: {
      page_key: field("Page key (home, projects or /route)", "text", 160, true),
      title: field("Title"),
      description: field("Description", "textarea", 2000),
      keywords: field("Keywords", "textarea", 5000),
      canonical_url: seo.canonical_url,
      og_image: seo.og_image,
      robots: seo.robots,
    },
  },
};
export const settingsFields = {
  site_name: field("Website name", "text", 255, true),
  header_logo_url: field("Header logo URL", "url", 700),
  favicon_url: field("Favicon URL", "url", 700),
  meta_image_url: field("Default social image URL", "url", 700),
  phone: field("Company phone", "text", 80),
  email: field("Company email", "email"),
  address: field("Company address", "textarea", 2000),
  footer_text: field("Footer text", "text", 2000),
};
export const homeFields = Object.fromEntries(
  [
    "hero_eyebrow",
    "hero_title",
    "hero_text",
    "about_title",
    "about_text",
    "contact_title",
    "contact_text",
  ].map((k) => [
    k,
    field(
      k.replaceAll("_", " "),
      k.endsWith("text") ? "textarea" : "text",
      k.endsWith("text") ? 10000 : 255,
    ),
  ]),
);
export const leadStatuses = [
  "New",
  "Contacted",
  "Estimate Scheduled",
  "Quote Sent",
  "Won",
  "Lost",
  "Quoted",
  "Closed",
];
export function parseFields(fields, body) {
  const shape = {};
  for (const [key, f] of Object.entries(fields)) {
    let schema;
    if (f.type === "checkbox")
      schema = z.preprocess(
        (v) => (v === "1" || v === "on" || v === true ? 1 : 0),
        z.number(),
      );
    else if (f.type === "number")
      schema = z.preprocess(
        (v) =>
          v === "" || v == null
            ? key === "square_footage"
              ? null
              : 0
            : Number(v),
        key === "square_footage"
          ? z.number().int().min(0).max(4294967295).nullable()
          : z.number().int().min(-100000).max(100000),
      );
    else {
      schema = z.string().trim().max(f.max);
      if (f.required) schema = schema.min(1);
      if (f.type === "slug")
        schema = schema.regex(
          /^[a-z0-9]+(?:-[a-z0-9]+)*$/,
          "Use lowercase letters, numbers and hyphens.",
        );
      if (f.type === "select") schema = z.enum(f.options);
      if (f.type === "email")
        schema = schema.refine(
          (v) => !v || z.email().safeParse(v).success,
          "Use a valid email.",
        );
      if (f.type === "date")
        schema = schema
          .refine(
            (v) =>
              !v ||
              (/^\d{4}-\d{2}-\d{2}$/.test(v) &&
                !Number.isNaN(Date.parse(v)) &&
                new Date(v).toISOString().startsWith(v)),
            "Use a valid date.",
          )
          .transform((v) => v || null);
      if (f.type === "url") schema = schema.transform(safeUrl);
      if (f.type === "html") schema = schema.transform(cleanHtml);
      schema = z.preprocess(
        (v) => (v == null ? (f.type === "select" ? f.options[0] : "") : v),
        schema,
      );
    }
    shape[key] = schema;
  }
  return z.object(shape).parse(body);
}
