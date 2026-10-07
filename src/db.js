import mysql from "mysql2/promise";
export function database(options) {
  const pool = mysql.createPool(options);
  const wrap = (connection) => ({
    async query(sql, params = []) {
      const [rows] = await connection.execute(sql, params);
      return rows;
    },
  });
  return {
    ...wrap(pool),
    pool,
    async transaction(fn) {
      const c = await pool.getConnection();
      try {
        await c.beginTransaction();
        const result = await fn(wrap(c));
        await c.commit();
        return result;
      } catch (e) {
        await c.rollback();
        throw e;
      } finally {
        c.release();
      }
    },
    close: () => pool.end(),
  };
}
