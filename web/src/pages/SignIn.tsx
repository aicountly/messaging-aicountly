import { useAuth } from '../auth/AuthProvider'

/**
 * Shown only when the automatic portal jump did not happen: after a deliberate
 * sign-out, or when sign-in failed and bouncing back to the portal would just
 * repeat it. Signing in is the portal's job, so this screen only points at it.
 */
export default function SignIn() {
  const { message, signIn } = useAuth()

  return (
    <main className="screen">
      <div className="panel">
        <div className="panel-brand">
          <img src="/assets/aicountly-logo.png" alt="" width={40} height={40} aria-hidden />
          <div>
            <strong>AICOUNTLY</strong>
            <small>Messaging</small>
          </div>
        </div>

        <h1 className="welcome">Messaging</h1>

        {/* A sign-in problem the user has to read, distinguished from a plain
            sign-out. Amber with text, never colour alone. */}
        {message !== null ? (
          <p className="notice-inline" role="status">
            {message}
          </p>
        ) : (
          <p className="message">You have been signed out.</p>
        )}

        <button type="button" className="button" onClick={signIn}>
          Sign in with Aicountly
        </button>

        <p className="message" style={{ margin: '1.5rem 0 0', fontSize: '0.8rem' }}>
          Aicountly Interactive Services Private Limited
        </p>
      </div>
    </main>
  )
}
