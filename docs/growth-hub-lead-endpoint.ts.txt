import { createFileRoute } from "@tanstack/react-router";

/**
 * Enquiries from sociallab.com.au.
 *
 * The website's PHP form handler POSTs here after emailing the team, with a
 * shared secret in the Authorization header. Leads arrive as `pending`, the
 * same as the email and Instagram integrations, so nothing jumps the review
 * queue. `tier` is left empty for whoever triages it to set.
 */

type WebsiteLead = {
  name?: string;
  email?: string;
  phone?: string;
  business_type?: string;
  business_name?: string;
  area?: string;
  interest?: string;
  address?: string;
  timing?: string;
  message?: string;
  source?: string;
  page?: string;
  submitted_at?: string;
};

/** Constant-time string comparison, so a wrong secret leaks nothing by timing. */
function secretMatches(given: string, expected: string): boolean {
  if (given.length !== expected.length) return false;
  let diff = 0;
  for (let i = 0; i < given.length; i++) diff |= given.charCodeAt(i) ^ expected.charCodeAt(i);
  return diff === 0;
}

/** The details that do not have their own column, kept readable in `message`. */
function composeMessage(lead: WebsiteLead): string {
  const details: Array<[string, string | undefined]> = [
    ["Business type", lead.business_type],
    ["Core suburb / area", lead.area],
    ["Interested in", lead.interest],
    ["Property address", lead.address],
    ["Timing", lead.timing],
  ];
  const lines = details
    .filter(([, value]) => value && value.trim() !== "")
    .map(([label, value]) => `${label}: ${value}`);

  if (lead.message && lead.message.trim() !== "") {
    lines.push("", lead.message.trim());
  }
  return lines.join("\n").slice(0, 8000);
}

export const Route = createFileRoute("/api/website/lead")({
  server: {
    handlers: {
      POST: async ({ request }) => {
        const expected = process.env["WEBSITE_LEAD_SECRET"] ?? "";
        if (!expected) {
          return Response.json({ ok: false, error: "WEBSITE_LEAD_SECRET is not set" }, { status: 500 });
        }

        const given = (request.headers.get("Authorization") ?? "").replace(/^Bearer\s+/i, "");
        if (!secretMatches(given, expected)) {
          return Response.json({ ok: false, error: "Not authorised" }, { status: 401 });
        }

        let lead: WebsiteLead;
        try {
          const body = (await request.json()) as { event?: string; lead?: WebsiteLead };
          if (!body.lead) throw new Error("Missing lead");
          lead = body.lead;
        } catch {
          return Response.json({ ok: false, error: "Invalid payload" }, { status: 400 });
        }

        const email = lead.email?.trim().toLowerCase();
        if (!email && !lead.phone) {
          return Response.json({ ok: false, error: "Lead needs an email or phone" }, { status: 422 });
        }

        const receivedAt = lead.submitted_at ?? new Date().toISOString();
        // Unique per submission, so a retry from the website cannot double up.
        const sourceRef = `website:${receivedAt}:${email ?? lead.phone}`;

        const { adminClient } = await import("@/server/api-auth.server");
        const db = await adminClient();

        const { data: seen } = await db.from("leads").select("id").eq("source_ref", sourceRef).maybeSingle();
        if (seen) return Response.json({ ok: true, id: seen.id, duplicate: true });

        const formName = lead.source ?? "Website enquiry";
        const { data, error } = await db
          .from("leads")
          .insert({
            source: "website",
            source_ref: sourceRef,
            received_at: receivedAt,
            name: lead.name ?? null,
            company: lead.business_name ?? null,
            email: email ?? null,
            phone: lead.phone ?? null,
            channel: "Website form",
            subject: formName,
            message: composeMessage(lead),
            ai_is_lead: true,
            ai_reason: `Submitted the ${formName} form on sociallab.com.au`,
            ai_summary: [lead.business_type, lead.interest, lead.area].filter(Boolean).join(" · ") || null,
            status: "pending",
          })
          .select("id")
          .single();

        if (error) {
          return Response.json({ ok: false, error: error.message }, { status: 500 });
        }
        return Response.json({ ok: true, id: data.id });
      },
    },
  },
});
