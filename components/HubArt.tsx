import { CoilArt } from "@/components/art/CoilArt";
import { hub } from "@/components/art/drawings";

/**
 * The hub illustration from Coilbox, rendered as a full-bleed backdrop on the
 * landing page. Used to hold its own copy of the renderer and the palette
 * maths, one hand copy at a time. Both now live in `components/art`, shared
 * with every other drawing this site uses, and this is a thin wrapper round
 * them kept so `app/page.tsx` does not need to change: same name, same props.
 *
 * See `components/art/drawings.ts` for what the drawing is, where it came
 * from and why the landing page keeps it, and `components/art/CoilArt.tsx`
 * for how `strength` and the cropped `viewBox` work.
 */
export function HubArt({
  className,
  strength = 1,
}: {
  className?: string;
  strength?: number;
}) {
  return <CoilArt drawing={hub} className={className} strength={strength} />;
}
