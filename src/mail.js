import nodemailer from "nodemailer";
export async function queueQuoteMail(tx, cfg, lead) {
  if (!cfg.mail.host || !cfg.from || !cfg.notify) return;
  for (const message of [
    {
      to: cfg.notify,
      subject: `New quote request: ${lead.name}`,
      body: `Name: ${lead.name}\nEmail: ${lead.email}\nPhone: ${lead.phone}\nProject: ${lead.project_type}\nLocation: ${lead.location}\n\n${lead.message}\n\nManage requests: ${cfg.siteUrl}/admin/leads`,
    },
    {
      to: lead.email,
      subject: "We received your project request",
      body: `Hi ${lead.name},\n\nThank you for contacting Galindos Builders LLC. We received your request and will contact you to discuss your project.\n\nGalindos Builders LLC\n${cfg.siteUrl}`,
    },
  ])
    await tx.query(
      "INSERT INTO mail_outbox(recipient,subject,body,available_at) VALUES(?,?,?,UTC_TIMESTAMP())",
      [message.to, message.subject, message.body],
    );
}
export async function deliverMail(
  db,
  cfg,
  transport = nodemailer.createTransport(cfg.mail),
) {
  if (!cfg.mail.host || !cfg.from) return 0;
  const claimed = await db.transaction(async (tx) => {
    const rows = await tx.query(
      "SELECT * FROM mail_outbox WHERE sent_at IS NULL AND attempts<8 AND available_at<=UTC_TIMESTAMP() AND (locked_until IS NULL OR locked_until<UTC_TIMESTAMP()) ORDER BY id LIMIT 10 FOR UPDATE",
    );
    for (const row of rows)
      await tx.query(
        "UPDATE mail_outbox SET locked_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE),attempts=attempts+1 WHERE id=?",
        [row.id],
      );
    return rows;
  });
  for (const row of claimed) {
    try {
      await transport.sendMail({
        from: cfg.from,
        to: row.recipient,
        subject: row.subject,
        text: row.body,
      });
      await db.query(
        "UPDATE mail_outbox SET sent_at=UTC_TIMESTAMP(),locked_until=NULL,last_error=NULL WHERE id=?",
        [row.id],
      );
    } catch (e) {
      await db.query(
        "UPDATE mail_outbox SET locked_until=NULL,available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),last_error=? WHERE id=?",
        [
          Math.min(3600, 30 * 2 ** row.attempts),
          String(e.code || "Delivery failed").slice(0, 500),
          row.id,
        ],
      );
    }
  }
  return claimed.length;
}
